<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Application;

use Zend\Mvc\ModuleRouteListener;
use Zend\Mvc\MvcEvent;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\TableGateway\TableGateway;
use Zend\Session\Config\SessionConfig;
use Zend\Session\Validator\HttpUserAgent;
use Zend\Session\Validator\RemoteAddr;
use Zend\Session\SessionManager;
use Zend\Session\Container;
use Application\Utility\Utilities;
use Application\Model\User;
use Application\Model\UserTable;
use Application\Model\Project;
use Application\Model\ProjectTable;
use Application\Model\ViewProjectMin;
use Application\Model\ViewProjectMinTable;
use Application\Model\ViewProjectDetails;
use Application\Model\ViewProjectDetailsTable;
use Application\Model\ViewProjectsMembersSpecializations;
use Application\Model\ViewProjectsMembersSpecializationsTable;
use Application\Model\ProjectsUsersMembers;
use Application\Model\ProjectsUsersMembersTable;
use Application\Model\Task;
use Application\Model\TaskTable;
use Application\Model\ViewUsersProjects;
use Application\Model\ViewUsersProjectsTable;
use Application\Model\ViewUsersTasks;
use Application\Model\ViewUsersTasksTable;
use Application\Model\UsersTasksAffectations;
use Application\Model\UsersTasksAffectationsTable;
use Application\Model\ViewTasksUsers;
use Application\Model\ViewTasksUsersTable;
use Application\Model\Event;
use Application\Model\EventTable;
use Application\Model\EventOnProjects;
use Application\Model\EventOnProjectsTable;
use Application\Model\EventUser;
use Application\Model\EventUserTable;
use Application\Model\ViewEvent;
use Application\Model\ViewEventTable;
use Application\Model\EventType;
use Application\Model\EventTypeTable;
use Application\Model\EventOnTask;
use Application\Model\EventOnTaskTable;
use Application\Model\ProjectsUsersSpecializations;
use Application\Model\ProjectsUsersSpecializationsTable;


@ini_set('zend_monitor.enable', 0);
if(@function_exists('output_cache_disable')) {
    @output_cache_disable();
}
if(isset($_GET['debugger_connect']) && $_GET['debugger_connect'] == 1) {
    if(function_exists('debugger_connect'))  {
        debugger_connect();
        exit();
    } else {
        echo "No connector is installed.";
    }
}


class Module
{
   public function onBootstrap(MvcEvent $e)
   {
      // Useful for getting the current controller and action's names in views.
		$eventManager = $e->getApplication()->getEventManager();
      $eventManager->attach(
         'dispatch',
         function($e) {
            $routeMatch = $e->getRouteMatch();
            $viewModel = $e->getViewModel();
            $viewModel->setVariable('controller', $routeMatch->getParam('controller'));
            $viewModel->setVariable('action', $routeMatch->getParam('action'));
         }
      );
      // Initialize route listener.
      $moduleRouteListener = new ModuleRouteListener();
      $moduleRouteListener->attach($eventManager);

		// Calls the bootstrap used for the session management.
		$this->initSession(array(
			'remember_me_seconds' => 300,	// The session stay persisting 5 minutes after the browser closing.
			'use_cookies' => true,
			'cookie_httponly' => true,
		));
   }

	// Session management.
	public function initSession($config)
	{
		$sessionConfig = new SessionConfig();
		$sessionConfig->setOptions($config);

		$sessionManager = new SessionManager($sessionConfig);
		$sessionManager->getValidatorChain()
			->attach(
				'session.validate',
				array(new RemoteAddr(), 'isValid')		// Validate the session by the user's IP address, for avoiding hijacking.
			);
		$sessionManager->getValidatorChain()
			->attach(
				'session.validate',
				array(new HttpUserAgent(), 'isValid')	// While the RemoteAddr validator can be spoofed or bypassed by a proxy, we also enable the HttpUserAgent one, which uses the HTTP user agent to validate the request.
			);
		$sessionManager->start();
		Container::setDefaultManager($sessionManager);
	}

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
			'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

	// Load objects related to the database's data.
	public function getServiceConfig()
	{
		return array(
			'factories' => array(
                // Return an instance of the Utility/Utilities class when a controler invoks the 'Application\Utility\Utilities' object.
                'Application\Utility\Utilities' => function($sm) { return new Utilities(); },
                // Declare the gateway between the database's entity (table,
                //  view, ...) and the exchange's class.
                'UserTableGateway' => function ($sm) { // Change the gateway's name.
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new User()); // Change the instance's class name.
                    return new TableGateway('users', $dbAdapter, null, $resultSetPrototype); // Change the table's name (this IS the table's name in the database).
                },
                // Use the gateway to give the [NameTable]Table's file the
                // exchange's class as parameter.
                'Application\Model\UserTable' =>  function($sm) { // Change the class' name.
                    $tableGateway = $sm->get('UserTableGateway'); // Change the gateway's name.
                    $table = new UserTable($tableGateway); // Change the instance's class name.
                    return $table;
                },
                'TaskTableGateway' => function($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new Task()); // Change the instance's class name.
                    return new TableGateway('tasks', $dbAdapter, null, $resultSetPrototype); // Change the table's name (this IS the table's name in the database).
                },
                'Application\Model\TaskTable' =>  function($sm) { // Change the class' name.
                    $tableGateway = $sm->get('TaskTableGateway'); // Change the gateway's name.
                    $table = new TaskTable($tableGateway); // Change the instance's class name.
                    return $table;
                },
                'ProjectTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new Project());
                    return new TableGateway('projects', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ProjectTable' =>  function($sm) {
                    $tableGateway = $sm->get('ProjectTableGateway');
                    $table = new ProjectTable($tableGateway);
                    return $table;
                },
                'ViewProjectMinTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewProjectMin());
                    return new TableGateway('view_projects_min', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewProjectMinTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewProjectMinTableGateway');
                    $table = new ViewProjectMinTable($tableGateway);
                    return $table;
                },
                'ViewProjectDetailsTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewProjectDetails());
                    return new TableGateway('view_projects_details', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewProjectDetailsTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewProjectDetailsTableGateway');
                    $table = new ViewProjectDetailsTable($tableGateway);
                    return $table;
                },
                'ProjectsUsersMembersTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ProjectsUsersMembers());
                    return new TableGateway('projectsUsersMembers', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ProjectsUsersMembersTable' =>  function($sm) {
                    $tableGateway = $sm->get('ProjectsUsersMembersTableGateway');
                    $table = new ProjectsUsersMembersTable($tableGateway);
                    return $table;
                },
                'ViewUsersProjectsTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewUsersProjects());
                    return new TableGateway('view_users_projects', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewUsersProjectsTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewUsersProjectsTableGateway');
                    $table = new ViewUsersProjectsTable($tableGateway);
                    return $table;
                },
                'ViewUsersTasksTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewUsersTasks());
                    return new TableGateway('view_users_tasks', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewUsersTasksTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewUsersTasksTableGateway');
                    $table = new ViewUsersTasksTable($tableGateway);
                    return $table;
                },
                'UsersTasksAffectationsTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new UsersTasksAffectations());
                    return new TableGateway('usersTasksAffectations', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\UsersTasksAffectationsTable' =>  function($sm) {
                    $tableGateway = $sm->get('UsersTasksAffectationsTableGateway');
                    $table = new UsersTasksAffectationsTable($tableGateway);
                    return $table;
                },
                'ViewProjectsMembersSpecializationsTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewProjectsMembersSpecializations());
                    return new TableGateway('view_projects_members_specializations', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewProjectsMembersSpecializationsTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewProjectsMembersSpecializationsTableGateway');
                    $table = new ViewProjectsMembersSpecializationsTable($tableGateway);
                    return $table;
                },
                'ViewTasksUsersTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewTasksUsers());
                    return new TableGateway('view_tasks_users', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewTasksUsersTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewTasksUsersTableGateway');
                    $table = new ViewTasksUsersTable($tableGateway);
                    return $table;
                },
                'EventTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new Event());
                    return new TableGateway('events', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\EventTable' =>  function($sm) {
                    $tableGateway = $sm->get('EventTableGateway');
                    $table = new EventTable($tableGateway);
                    return $table;
                },
                'EventOnProjectsTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new EventOnProjects());
                    return new TableGateway('eventsOnProjects', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\EventOnProjectsTable' =>  function($sm) {
                    $tableGateway = $sm->get('EventOnProjectsTableGateway');
                    $table = new EventOnProjectsTable($tableGateway);
                    return $table;
                },
                'EventUserTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new EventUser());
                    return new TableGateway('eventsUsers', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\EventUserTable' =>  function($sm) {
                    $tableGateway = $sm->get('EventUserTableGateway');
                    $table = new EventUserTable($tableGateway);
                    return $table;
                },
                'ViewEventTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ViewEvent());
                    return new TableGateway('view_events', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ViewEventTable' =>  function($sm) {
                    $tableGateway = $sm->get('ViewEventTableGateway');
                    $table = new ViewEventTable($tableGateway);
                    return $table;
                },
                'EventTypeTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new EventType());
                    return new TableGateway('eventTypes', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\EventTypeTable' =>  function($sm) {
                    $tableGateway = $sm->get('EventTypeTableGateway');
                    $table = new EventTypeTable($tableGateway);
                    return $table;
                },
                'EventOnTaskTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new EventOnTask());
                    return new TableGateway('eventsOnTasks', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\EventOnTaskTable' =>  function($sm) {
                    $tableGateway = $sm->get('EventOnTaskTableGateway');
                    $table = new EventOnTaskTable($tableGateway);
                    return $table;
                },
                'ProjectsUsersSpecializationsTableGateway' => function ($sm) {
                    $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                    $resultSetPrototype = new ResultSet();
                    $resultSetPrototype->setArrayObjectPrototype(new ProjectsUsersSpecializations());
                    return new TableGateway('projectsUsersSpecializations', $dbAdapter, null, $resultSetPrototype);
                },
                'Application\Model\ProjectsUsersSpecializationsTable' =>  function($sm) {
                    $tableGateway = $sm->get('ProjectsUsersSpecializationsTableGateway');
                    $table = new ProjectsUsersSpecializationsTable($tableGateway);
                    return $table;
                },
				// Configure the session service.
				'Zend\Session\SessionManager' => function ($sm) {
                    $config = $sm->get('config');
                    if (isset($config['session'])) {
                        $session = $config['session'];

                        $sessionConfig = null;
                        if (isset($session['config'])) {
                            $class = isset($session['config']['class'])  ? $session['config']['class'] : 'Zend\Session\Config\SessionConfig';
                            $options = isset($session['config']['options']) ? $session['config']['options'] : array();
                            $sessionConfig = new $class();
                            $sessionConfig->setOptions($options);
                        }

                        $sessionStorage = null;
                        if (isset($session['storage'])) {
                            $class = $session['storage'];
                            $sessionStorage = new $class();
                        }

                        $sessionSaveHandler = null;
                        if (isset($session['save_handler'])) {
                            // class should be fetched from service manager since it will require constructor arguments
                            $sessionSaveHandler = $sm->get($session['save_handler']);
                        }

                        $sessionManager = new SessionManager($sessionConfig, $sessionStorage, $sessionSaveHandler);

                        if (isset($session['validator'])) {
                            $chain = $sessionManager->getValidatorChain();
                            foreach ($session['validator'] as $validator) {
                                $validator = new $validator();
                                $chain->attach('session.validate', array($validator, 'isValid'));

                            }
                        }
                    } else {
                        $sessionManager = new SessionManager();
                    }
                    Container::setDefaultManager($sessionManager);
                    return $sessionManager;
                },
			),
		);
	}
}
