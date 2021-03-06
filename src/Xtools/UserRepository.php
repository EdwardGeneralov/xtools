<?php
/**
 * This file contains only the UserRepository class.
 */

namespace Xtools;

use Mediawiki\Api\SimpleRequest;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * This class provides data for the User class.
 * @codeCoverageIgnore
 */
class UserRepository extends Repository
{
    /**
     * Convenience method to get a new User object.
     * @param string $username The username.
     * @param Container $container The DI container.
     * @return User
     */
    public static function getUser($username, Container $container)
    {
        $user = new User($username);
        $userRepo = new UserRepository();
        $userRepo->setContainer($container);
        $user->setRepository($userRepo);
        return $user;
    }

    /**
     * Get the user's ID.
     * @param string $databaseName The database to query.
     * @param string $username The username to find.
     * @return int
     */
    public function getId($databaseName, $username)
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_id');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $userTable = $this->getTableName($databaseName, 'user');
        $sql = "SELECT user_id FROM $userTable WHERE user_name = :username LIMIT 1";
        $resultQuery = $this->executeProjectsQuery($sql, ['username' => $username]);
        $userId = (int)$resultQuery->fetchColumn();

        // Cache and return.
        return $this->setCache($cacheKey, $userId);
    }

    /**
     * Get the user's registration date.
     * @param string $databaseName The database to query.
     * @param string $username The username to find.
     * @return string|null As returned by the database.
     */
    public function getRegistrationDate($databaseName, $username)
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_registration');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $userTable = $this->getTableName($databaseName, 'user');
        $sql = "SELECT user_registration FROM $userTable WHERE user_name = :username LIMIT 1";
        $resultQuery = $this->executeProjectsQuery($sql, ['username' => $username]);
        $registrationDate = $resultQuery->fetchColumn();

        // Cache and return.
        return $this->setCache($cacheKey, $registrationDate);
    }

    /**
     * Get the user's (system) edit count.
     * @param string $databaseName The database to query.
     * @param string $username The username to find.
     * @return int|null As returned by the database.
     */
    public function getEditCount($databaseName, $username)
    {
        $userTable = $this->getTableName($databaseName, 'user');
        $sql = "SELECT user_editcount FROM $userTable WHERE user_name = :username LIMIT 1";
        $resultQuery = $this->executeProjectsQuery($sql, ['username' => $username]);
        return $resultQuery->fetchColumn();
    }

    /**
     * Get group names of the given user.
     * @param Project $project The project.
     * @param string $username The username.
     * @return string[]
     */
    public function getGroups(Project $project, $username)
    {
        // Use md5 to ensure the key does not contain reserved characters.
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_groups');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $this->stopwatch->start($cacheKey, 'XTools');
        $api = $this->getMediawikiApi($project);
        $params = [
            'list' => 'users',
            'ususers' => $username,
            'usprop' => 'groups'
        ];
        $query = new SimpleRequest('query', $params);
        $result = [];
        $res = $api->getRequest($query);
        if (isset($res['batchcomplete']) && isset($res['query']['users'][0]['groups'])) {
            $result = $res['query']['users'][0]['groups'];
        }

        // Cache and return.
        $this->stopwatch->stop($cacheKey);
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get a user's global group membership (starting at XTools' default project if none is
     * provided). This requires the CentralAuth extension to be installed.
     * @link https://www.mediawiki.org/wiki/Extension:CentralAuth
     * @param string $username The username.
     * @param Project $project The project to query.
     * @return string[]
     */
    public function getGlobalGroups($username, Project $project = null)
    {
        // Get the default project if not provided.
        if (!$project instanceof Project) {
            $project = ProjectRepository::getDefaultProject($this->container);
        }

        // Create the API query.
        $api = $this->getMediawikiApi($project);
        $params = [
            'meta' => 'globaluserinfo',
            'guiuser' => $username,
            'guiprop' => 'groups'
        ];
        $query = new SimpleRequest('query', $params);

        // Get the result.
        $res = $api->getRequest($query);
        $result = [];
        if (isset($res['batchcomplete']) && isset($res['query']['globaluserinfo']['groups'])) {
            $result = $res['query']['globaluserinfo']['groups'];
        }
        return $result;
    }

    /**
     * Search the ipblocks table to see if the user is currently blocked
     * and return the expiry if they are.
     * @param $databaseName The database to query.
     * @param $userId The ID of the user to search for.
     * @return bool|string Expiry of active block or false
     */
    public function getBlockExpiry($databaseName, $userId)
    {
        $ipblocksTable = $this->getTableName($databaseName, 'ipblocks');
        $sql = "SELECT ipb_expiry
                FROM $ipblocksTable
                WHERE ipb_user = :userId
                LIMIT 1";
        $resultQuery = $this->executeProjectsQuery($sql, ['userId' => $userId]);
        return $resultQuery->fetchColumn();
    }

    /**
     * Get edit count within given timeframe and namespace.
     * @param Project $project
     * @param User $user
     * @param int|string $namespace Namespace ID or 'all' for all namespaces
     * @param string $start Start date in a format accepted by strtotime()
     * @param string $end End date in a format accepted by strtotime()
     */
    public function countEdits(Project $project, User $user, $namespace = 'all', $start = '', $end = '')
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_editcount');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        list($condBegin, $condEnd) = $this->getRevTimestampConditions($start, $end);
        list($pageJoin, $condNamespace) = $this->getPageAndNamespaceSql($project, $namespace);
        $revisionTable = $project->getTableName('revision');

        $sql = "SELECT COUNT(rev_id)
                FROM $revisionTable
                $pageJoin
                WHERE rev_user_text = :username
                $condNamespace
                $condBegin
                $condEnd";

        $resultQuery = $this->executeQuery($sql, $user, $namespace, $start, $end);
        $result = $resultQuery->fetchColumn();

        // Cache and return.
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get information about the currently-logged in user.
     * @return array
     */
    public function getXtoolsUserInfo()
    {
        /** @var Session $session */
        $session = $this->container->get('session');
        return $session->get('logged_in_user');
    }

    /**
     * Maximum number of edits to process, based on configuration.
     * @return int
     */
    public function maxEdits()
    {
        return $this->container->getParameter('app.max_user_edits');
    }

    /**
     * Get SQL clauses for joining on `page` and restricting to a namespace.
     * @param  Project $project
     * @param  int|string $namespace Namespace ID or 'all' for all namespaces.
     * @return array [page join clause, page namespace clause]
     */
    protected function getPageAndNamespaceSql(Project $project, $namespace)
    {
        if ($namespace === 'all') {
            return [null, null];
        }

        $pageTable = $project->getTableName('page');
        $pageJoin = $namespace !== 'all' ? "LEFT JOIN $pageTable ON rev_page = page_id" : null;
        $condNamespace = 'AND page_namespace = :namespace';

        return [$pageJoin, $condNamespace];
    }

    /**
     * Get SQL clauses for rev_timestamp, based on whether values for
     * the given start and end parameters exist.
     * @param  string $start
     * @param  string $end
     * @param string $tableAlias Alias of table FOLLOWED BY DOT.
     * @todo FIXME: merge with Repository::getDateConditions
     * @return string[] Clauses for start and end timestamps.
     */
    protected function getRevTimestampConditions($start, $end, $tableAlias = '')
    {
        $condBegin = '';
        $condEnd = '';

        if (!empty($start)) {
            $condBegin = "AND {$tableAlias}rev_timestamp >= :start ";
        }
        if (!empty($end)) {
            $condEnd = "AND {$tableAlias}rev_timestamp <= :end ";
        }

        return [$condBegin, $condEnd];
    }

    /**
     * Prepare the given SQL, bind the given parameters, and execute the Doctrine Statement.
     * @param  string $sql
     * @param  User   $user
     * @param  string $namespace
     * @param  string $start
     * @param  string $end
     * @return \Doctrine\DBAL\Statement
     */
    protected function executeQuery($sql, User $user, $namespace = 'all', $start = '', $end = '')
    {
        $params = [
            'username' => $user->getUsername(),
        ];

        if (!empty($start)) {
            $params['start'] = date('Ymd000000', strtotime($start));
        }
        if (!empty($end)) {
            $params['end'] = date('Ymd235959', strtotime($end));
        }
        if ($namespace !== 'all') {
            $params['namespace'] = $namespace;
        }

        return $this->executeProjectsQuery($sql, $params);
    }
}
