<?php
/**
 * This file contains only the CategoryEditsController class.
 */

namespace AppBundle\Controller;

use AppBundle\Helper\AutomatedEditsHelper;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xtools\CategoryEdits;
use Xtools\CategoryEditsRepository;
use Xtools\Edit;
use Xtools\Project;
use Xtools\ProjectRepository;
use Xtools\User;
use Xtools\UserRepository;

/**
 * This controller serves the Category Edits tool.
 */
class CategoryEditsController extends XtoolsController
{
    /** @var CategoryEdits The CategoryEdits instance. */
    protected $categoryEdits;

    /** @var Project The project. */
    protected $project;

    /** @var User The user. */
    protected $user;

    /** @var string The start date. */
    protected $start;

    /** @var string The end date. */
    protected $end;

    /** @var int|string The namespace ID or 'all' for all namespaces. */
    protected $namespace;

    /** @var bool Whether or not this is a subrequest. */
    protected $isSubRequest;

    /** @var array Data that is passed to the view. */
    private $output;

    /**
     * Get the tool's shortname.
     * @return string
     * @codeCoverageIgnore
     */
    public function getToolShortname()
    {
        return 'categoryedits';
    }

    /**
     * Display the search form.
     * @Route("/categoryedits", name="categoryedits")
     * @Route("/categoryedits/", name="CategoryEditsSlash")
     * @Route("/catedits", name="CategoryEditsShort")
     * @Route("/catedits/", name="CategoryEditsShortSlash")
     * @param Request $request The HTTP request.
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $params = $this->parseQueryParams($request);

        // Redirect if at minimum project, username and categories are provided.
        if (isset($params['project']) && isset($params['username']) && isset($params['categories'])) {
            return $this->redirectToRoute('CategoryEditsResult', $params);
        }

        // Convert the given project (or default project) into a Project instance.
        $params['project'] = $this->getProjectFromQuery($params);

        return $this->render('categoryEdits/index.html.twig', array_merge([
            'xtPageTitle' => 'tool-categoryedits',
            'xtSubtitle' => 'tool-categoryedits-desc',
            'xtPage' => 'categoryedits',

            // Defaults that will get overriden if in $params.
            'namespace' => 0,
            'start' => '',
            'end' => '',
        ], $params));
    }

    /**
     * Set defaults, and instantiate the CategoryEdits model. This is called at
     * the top of every view action.
     * @param Request $request The HTTP request.
     * @codeCoverageIgnore
     */
    private function setupCategoryEdits(Request $request)
    {
        // Will redirect back to index if the user has too high of an edit count.
        $ret = $this->validateProjectAndUser($request, 'categoryedits');
        if ($ret instanceof RedirectResponse) {
            return $ret;
        } else {
            list($this->project, $this->user) = $ret;
        }

        $categories = $request->get('categories');
        $namespace = $request->get('namespace');
        $start = $request->get('start');
        $end = $request->get('end');
        $offset = $request->get('offset', 0);

        // 'false' means the dates are optional and returned as 'false' if empty.
        list($this->start, $this->end) = $this->getUTCFromDateParams($start, $end, false);

        // Format dates as needed by User model, if the date is present.
        if ($this->start !== false) {
            $this->start = date('Y-m-d', $this->start);
        }
        if ($this->end !== false) {
            $this->end = date('Y-m-d', $this->end);
        }

        // Normalize default namespace.
        if ($namespace == '') {
            $this->namespace = 'all';
        } else {
            $this->namespace = $namespace;
        }

        $this->categoryEdits = new CategoryEdits(
            $this->project,
            $this->user,
            $this->categories,
            $this->namespace,
            $this->start,
            $this->end,
            $offset
        );
        $categoryEditsRepo = new CategoryEditsRepository();
        $categoryEditsRepo->setContainer($this->container);
        $this->categoryEdits->setRepository($categoryEditsRepo);

        $this->isSubRequest = $request->get('htmlonly')
            || $this->get('request_stack')->getParentRequest() !== null;

        $this->output = [
            'xtPage' => 'categoryedits',
            'xtTitle' => $this->user->getUsername(),
            'project' => $this->project,
            'user' => $this->user,
            'ae' => $this->categoryEdits,
            'is_sub_request' => $this->isSubRequest,
        ];
    }

    /**
     * Display the results.
     * @Route(
     *     "/categoryedits/{project}/{username}/{categories}/{namespace}/{start}/{end}",
     *     name="CategoryEditsResult",
     *     requirements={
     *         "namespace" = "|all|\d+",
     *         "start" = "|\d{4}-\d{2}-\d{2}",
     *         "end" = "|\d{4}-\d{2}-\d{2}",
     *         "namespace" = "|all|\d+"
     *     },
     *     defaults={"namespace" = 0, "start" = "", "end" = ""}
     * )
     * @param Request $request The HTTP request.
     * @return RedirectResponse|Response
     * @codeCoverageIgnore
     */
    public function resultAction(Request $request)
    {
        // Will redirect back to index if the user has too high of an edit count.
        $ret = $this->setupCategoryEdits($request);
        if ($ret instanceof RedirectResponse) {
            return $ret;
        }

        // Render the view with all variables set.
        return $this->render('categoryEdits/result.html.twig', $this->output);
    }

    /************************ API endpoints ************************/

    /**
     * Count the number of category edits the given user has made.
     * @Route(
     *   "/api/user/category_editcount/{project}/{username}/{categories}/{namespace}/{start}/{end}/{tools}",
     *   requirements={
     *       "namespace" = "|all|\d+",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}"
     *   },
     *   defaults={"namespace" = "all", "start" = "", "end" = ""}
     * )
     * @param Request $request The HTTP request.
     * @param string $tools Non-blank to show which tools were used and how many times.
     * @return Response
     * @codeCoverageIgnore
     */
    public function categoryEditCountApiAction(Request $request, $tools = '')
    {
        $this->recordApiUsage('user/category_editcount');

        $ret = $this->setupCategoryEdits($request);
        if ($ret instanceof RedirectResponse) {
            // FIXME: Refactor JSON errors/responses, use Intuition as a service.
            return new JsonResponse(
                [
                    'error' => $this->getFlashMessage(),
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $res = $this->getJsonData();
        $res['total_editcount'] = $this->categoryEdits->getEditCount();

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);

        $res['category_editcount'] = $this->categoryEdits->getCount();

        $response->setData($res);
        return $response;
    }

    /**
     * Get data that will be used in API responses.
     * @return array
     * @codeCoverageIgnore
     */
    private function getJsonData()
    {
        $ret = [
            'project' => $this->project->getDomain(),
            'username' => $this->user->getUsername(),
        ];

        foreach (['categories', 'namespace', 'start', 'end', 'offset'] as $param) {
            if (isset($this->{$param}) && $this->{$param} != '') {
                $ret[$param] = $this->{$param};
            }
        }

        return $ret;
    }
}
