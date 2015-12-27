<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

// The namespace is important. It avoids us from being forced to call the Zend's methods with
// "Application\Controller" before.
namespace Application\Controller;

// Calling some useful Zend's libraries.
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\View\Model\JsonModel;
use Zend\Session\Config\SessionConfig;
use Zend\Session\Container;
use Zend\Http\Client;
use Zend\Http\Request;
use Application\Utility\Priority;

// Project controller ; will be calling when the user access the "easygoing/project" page.
// Be careful about the class' name, which must be the same as the file's name.
class ProjectController extends AbstractActionController
{
   // Will contain the Utility class.
   private $_utilities;

   // Get utilities functions.
   // Act as a singleton : we only can have one instance of the object.
   private function _getUtilities()
   {
      if (!$this->_utilities)
      {
         $sm = $this->getServiceLocator();
         $this->_utilities = $sm->get('Application\Utility\Utilities');
      }
      return $this->_utilities;
   }

   // Get the given table's entity, represented by the created model.
   private function _getTable($tableName)
   {
      $sm = $this->getServiceLocator();
      // Instanciate the object with the created model.
      $table = $sm->get('Application\Model\\'.$tableName);

      return $table;
   }

   // Acts like a filter : every request go through the dispatcher, in which we
   // can do some stuff.
   // In this case, we just prevent unconnected users to access this controller
   // and check if the accessed project/task exists.
   public function onDispatch( \Zend\Mvc\MvcEvent $e )
   {
      $sessionUser = new container('user');

      if (!$sessionUser->connected)
      {
         $this->redirect()->toRoute('home');
      }

      if (empty($this->_getTable('ProjectTable')->getProject($this->params('id'))))
      {
         $this->redirect()->toRoute('projects');
      }

      if ($this->params('otherId') != null && empty($this->_getTable('TaskTable')->getTaskById($this->params('otherId'))))
      {
         $this->redirect()->toRoute('projects');
      }

      return parent::onDispatch($e);
   }

   public function indexAction()
   {
      $sessionUser = new container('user');
      $project = $this->_getTable('ProjectTable')->getProject($this->params('id'));
      $tasks = $this->_getTable('TaskTable')->getAllTasksInProject($this->params('id'));
      $members = $this->_getTable('ViewUsersProjectsTable')->getUsersInProject($this->params('id'));
      // Get projects' events types.
      $eventsTypes = $this->_getTable('EventTypeTable')->getTypes(false);
      // Get project's events.
      $events = $this->_getTable('ViewEventTable')->getEntityEvents($this->params('id'), false);
      $isManager = $this->_userIsAdminOfProject($sessionUser->id, $this->params('id'));

      return new ViewModel(array(
         'project'      => $project,
         'tasks'        => $tasks,
         'members'      => $members,
         'eventsTypes'  => $eventsTypes,
         'events'       => $events,
         'isManager'    => $isManager ? true : false,
         'userId'       => $sessionUser->id
      ));
   }

   public function editAction()
   {
      define("SUCCESS_MESSAGE", "ok");
      $sessionUser = new container('user');
      $projectId = $this->params("id");
      // Get connected user's rights on the project.
      $rights = $this->_getTable("ProjectsUsersMembersTable")->getMemberRight($sessionUser->id, $projectId);
      // The user can edit the project only if he is an admin of it.
      if ($rights->isAdmin)
      {
         $request = $this->getRequest();
         // Get project's data.
         $project = $this->_getTable("ProjectTable")->getProject($projectId);

         if ($request->isPost())
         {
            // Operation's result message.
            $result = SUCCESS_MESSAGE;
            // Posted values.
            $name = $_POST["name"];
            $description = (empty($_POST["description"]) ? "-" : $_POST["description"]);
            $startDate = date_parse($_POST["startDate"]);
            $deadline = date_parse($_POST["deadline"]);
            // Will be used attribute a name to the uploaded file.
            $fileName;
            // Checks that the mandatory fields aren't empty.
            if (!empty($name) && !empty($startDate) && !empty($deadline))
            {
               // The dates must be valid dates and the deadline must be greater
               // than the start date.
               if ($startDate["error_count"] == 0 && checkdate($startDate["month"], $startDate["day"], $startDate["year"]) &&
                  $deadline["error_count"] == 0 && checkdate($deadline["month"], $deadline["day"], $deadline["year"]) &&
                  $startDate <= $deadline)
               {
                  // Indicate if the prospective project's logo is valid or not.
                  $fileValidated = true;
                  // If the user mentioned a logo, validate it.
                  if (!empty($_FILES["logo"]["name"]))
                  {
                     // Allowed file's extensions.
                     $allowedExts = array("jpeg", "JPEG", "jpg", "JPG", "png", "PNG");
                     // Get the file's extension.
                     $temp = explode(".", $_FILES["logo"]["name"]);
                     $extension = end($temp);
                     // Validates the file's size.
                     if ($_FILES["logo"]["size"] > 5 * 1024 * 1024 || !$_FILES["logo"]["size"])
                     {
                        $result = "errorLogoSize";
                        $fileValidated = false;
                     }
                     // Validates the file's type.
                     else if (($_FILES["logo"]["type"] != "image/jpeg") &&
                        ($_FILES["logo"]["type"] != "image/jpg") &&
                        ($_FILES["logo"]["type"] != "image/pjpeg") &&
                        ($_FILES["logo"]["type"] != "image/x-png") &&
                        ($_FILES["logo"]["type"] != "image/png"))
                     {
                        $result = "errorLogoType";
                        $fileValidated = false;
                     }
                     // Validates the file's extension.
                     else if (!in_array($extension, $allowedExts))
                     {
                        $result = "errorLogoExtension";
                        $fileValidated = false;
                     }
                     // Check that there is no error in the file.
                     else if ($_FILES["logo"]["error"] > 0)
                     {
                        $result = "errorLogo";
                        $fileValidated = false;
                     }
                     // If the file is valid, upload the picture.
                     else
                     {
                        try
                        {
                           // Generate a time-based unique ID, and check that this file's name doesn't exist yet.
                           do
                           {
                              $fileName = uniqid() . ".png";
                           }
                           while (file_exists(getcwd() . "/public/img/projects/" . $fileName));
                           // First move the temporary uploaded file in the server's directory to
                           // avoid some extensions issues with some OS.
                           move_uploaded_file($_FILES['logo']['tmp_name'], getcwd() . "/public/img/projects/tmp/" . $_FILES["logo"]["name"]);
                           // Then create a thumbnail (50px) of the image and save it in the hard drive of the server.
                           $this->_getUtilities()->createSquareImage(getcwd() . "/public/img/projects/tmp/" . $_FILES["logo"]["name"], $extension, getcwd() . "/public/img/projects/" . $fileName, 150);
                        }
                        catch (\Exception $e)
                        {
                           $result = "errorFilesUpload";
                        }

                        // Delete the temporary file if it exists.
                        if (file_exists(getcwd() . "/public/img/projects/tmp/" . $_FILES["logo"]["name"]))
                           unlink(getcwd() . "/public/img/projects/tmp/" . $_FILES["logo"]["name"]);

                        // Delete old project's logo if it wasn't the default one.
                        if ($project->fileLogo != "default.png")
                        {
                           if (file_exists(getcwd() . "/public/img/projects/" . $project->fileLogo))
                              unlink(getcwd() . "/public/img/projects/" . $project->fileLogo);
                        }
                     }
                  }
                  // If there is no file or the file is valid, we can edit the
                  // project in the database.
                  if ($fileValidated)
                  {
                     // Edits the project in the database.
                     if ($result == SUCCESS_MESSAGE)
                     {
                        try
                        {
                           $editedProject = array(
                              'name'			=> $name,
                              'description'	=> $description,
                              'startDate'		=> $_POST["startDate"],
                              'deadLineDate'	=> $_POST["deadline"],
                              'fileLogo'		=> isset($fileName) ? $fileName : $project->fileLogo
                           );
                           $this->_getTable("ProjectTable")->editProject($projectId, $editedProject);

                           // If project was successfully edited, add a project's edition event.
                           // First of all, get right event type.
                           $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Project")->id;
                           // Then add the new creation event in the database.
                           $message = "<u>" . $sessionUser->username . "</u> edited the project.";
                           $details =
                              "<table class='eventDetailsTable'>
                                 <tr>
                                    <th class='eventDetailsTaskAttribute'></th>
                                    <th>Old values</th>
                                    <th>New values</th>
                                 </tr>
                                 <tr>
                                    <td class='eventDetailsTaskAttribute'>Name: </td>
                                    <td>" . $project->name . "</td>
                                    <td>" . $name . "</td>
                                 </tr>
                                 <tr>
                                    <td class='eventDetailsTaskAttribute'>Description: </td>
                                    <td>" . $project->description . "</td>
                                    <td>" . $description . "</td>
                                 </tr>
                                 <tr>
                                    <td class='eventDetailsTaskAttribute'>Startdate: </td>
                                    <td>" . $project->startDate . "</td>
                                    <td>" . $_POST["startDate"] . "</td>
                                 </tr>
                                 <tr>
                                    <td class='eventDetailsTaskAttribute'>Deadline: </td>
                                    <td>" . $project->deadLineDate . "</td>
                                    <td>" . $_POST["deadline"] . "</td>
                                 </tr>
                                 <tr>
                                    <td class='eventDetailsTaskAttribute'>Logo: </td>
                                    <td></td>
                                    <td>" . (isset($fileName) ? "Aw yeah! New logo!" : "No new logo :(") . "</td>
                                 </tr>
                              </table>";
                           $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId, $details);
                           // Link the new event to the new project.
                           $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
                           // Finaly link the new event to the user who created it.
                           $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
   							}
                        catch (\Exception $e)
                        {
                           $result = "errorDatabaseAdding";
                        }
                     }
                  }
               }
               else
               {
                  $result = "errorDate";
               }
            }
            else
            {
               $result = "errorFieldEmpty";
            }

            // Deletes the uploaded file if there was an error.
            // If not, redirect the user.
            if ($result == SUCCESS_MESSAGE)
            {
               $this->redirect()->toRoute('project', array(
                   'id' => $projectId
               ));
            }
            else
            {
               // Delete the tumbnail, if it exists.
               if (isset($fileName) && file_exists(getcwd() . "/public/img/projects/" . $fileName))
                  unlink(getcwd() . "/public/img/projects/" . $fileName);

               return new ViewModel(array(
                  'error'        => $result,
                  'name'         => $name,
                  'description'  => $description,
                  'startDate'    => $_POST["startDate"],
                  'deadline'     => $_POST["deadline"],
                  'logo'         => $project->fileLogo
               ));
            }
         }
         else
         {
            // If there is no POST request, send project's data to the view.
            return new ViewModel(array(
               "project"   => $project
            ));
         }
      }
      else
      {
         $this->redirect()->toRoute('projects');
      }
   }

   public function taskAction()
   {
      return new ViewModel(array(
         'id' => $this->params('id')
      ));
   }

   public function addTaskAction()
   {
      $request = $this->getRequest();

      if($request->isPost())
      {
         $sessionUser = new container('user');

         $projectId = $this->params('id');
         $name = $_POST["name"];
         $description = $_POST["description"];
         $priority = $_POST["priority"];
         $deadline = $_POST["deadline"];
         $duration = $_POST["duration"];
         $sessionUser = new container('user');

         $taskId = $this->_getTable('TaskTable')->addTask($name, $description, $deadline, $duration, $priority, $projectId);
         $this->_getTable('UsersTasksAffectationsTable')->addAffectation($sessionUser->id, $taskId);

         // If task was successfully added, add two task's creation events: one for
         // the project's history, and another for the new task's news feed.
         // For the project's history.
         // First of all, get right event type.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
         // Then add the new event in the database.
         $message = "<u>" . $sessionUser->username . "</u> created task <font color=\"#FF6600\">" . $name . "</font>.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         // Link the new event to the current project.
         $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event = $this->_getTable("ViewEventTable")->getEvent($eventId, false);
         // For the task's news feed.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Info")->id;
         $message = "\"" . $sessionUser->username . "\" created the task.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         $this->_getTable("EventOnTaskTable")->add($eventId, $taskId);
         // Get SYSTEM user's ID and link it to the new task's event.
         $systemUserId = $this->_getTable("UserTable")->getSystemUser()->id;
         $this->_getTable("EventUserTable")->add(($systemUserId ? $systemUserId : $sessionUser->id), $eventId);

         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data.
            $client->setParameterPost(array(
               "requestType"        => "newEvent",
               "event"              => json_encode($event)
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }

         $this->redirect()->toRoute('project', array(
             'id' => $projectId
         ));
      }
   }

   public function taskDetailsAction()
   {
      $taskId = $this->params('otherId');
      $projectId = $this->params('id');
      $task = $this->_getTable('TaskTable')->getTaskById($taskId);
      // Get tasks' events types.
      $eventsTypes = $this->_getTable('EventTypeTable')->getTypes(true);
      // Get task's events.
      $events = $this->_getTable('ViewEventTable')->getEntityEvents($taskId, true);

      return new ViewModel(array(
         'task'         => $task,
         'projectId'    => $projectId,
         'eventsTypes'  => $eventsTypes,
         'events'       => $events
      ));
   }

   public function editTaskAction()
   {
      $request = $this->getRequest();

      if($request->isPost())
      {
         $sessionUser = new container('user');
         $projectId = $this->params('id');

         $id = $_POST["id"];
         $name = $_POST["name"];
         $description = $_POST["description"];
         $priority = $_POST["priority"];
         $deadline = $_POST["deadline"];
         $duration = $_POST["duration"];

         // Get old task's data for the historical.
         $oldTaskData = $this->_getTable('TaskTable')->getTaskById($id);
         // Upload task's data.
         $this->_getTable('TaskTable')->updateTask($name, $description, $deadline, $duration, $priority, $id);

         // If task was successfully edited, add a task's edition event.
         // First of all, get right event type.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
         // Then add the new event in the database.
         $message = "<u>" . $sessionUser->username . "</u> updated task <font color=\"#FF6600\">" . $name . "</font>.";
         // This event have some details.
         // TODO: use priority with BasicEnum.
         $priorityArray = ['High', 'Medium', 'Low'];
         $details =
            "<table class='eventDetailsTable'>
               <tr>
                  <th class='eventDetailsTaskAttribute'></th>
                  <th>Old values</th>
                  <th>New values</th>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Name: </td>
                  <td>" . $oldTaskData->name . "</td>
                  <td>" . $name . "</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Deadline: </td>
                  <td>" . $oldTaskData->deadLineDate . "</td>
                  <td>" . $deadline . "</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Duration: </td>
                  <td>" . $oldTaskData->durationsInHours . "h</td>
                  <td>" . $duration . "h</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Priority: </td>
                  <td>" . $priorityArray[$oldTaskData->priorityLevel - 1] . "</td>
                  <td>" . $priorityArray[$priority - 1] . "</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Description: </td>
                  <td>" . (empty($oldTaskData->description) ? "-" : $oldTaskData->description) . "</td>
                  <td>" . (empty($description) ? "-" : $description) . "</td>
               </tr>
            </table>";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId, $details);
         // Link the new event to the current project.
         $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event1 = $this->_getTable("ViewEventTable")->getEvent($eventId, false);
         // For the task's news feed.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Info")->id;
         $message = "\"" . $sessionUser->username . "\" updated the task.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         $this->_getTable("EventOnTaskTable")->add($eventId, $id);
         // Get SYSTEM user's ID and link it to the new task's event.
         $systemUserId = $this->_getTable("UserTable")->getSystemUser()->id;
         $this->_getTable("EventUserTable")->add(($systemUserId ? $systemUserId : $sessionUser->id), $eventId);
         $event2 = $this->_getTable("ViewEventTable")->getEvent($eventId, true);

         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data.
            $client->setParameterPost(array(
               "requestType"  => "newEvents",
               "events"       => array(json_encode($event1), json_encode($event2))
            ));
            // Send HTTP request to server.
            $response = $client->send();
            // Send a edit request to inform users which are currently in the task page.
            $client->setParameterPost(array(
               "requestType"  => "taskEdited",
               "taskId"       => $id,
               "data"         => json_encode(array(
                                    "name"         => $name,
                                    "deadline"     => $deadline,
                                    "duration"     => $duration,
                                    "priority"     => $priority,
                                    "description"  => $description
                                 ))
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }

         $this->redirect()->toRoute('project', array(
             'id' => $this->params('id')
         ));
      }
      else
      {
         $taskId = $this->params('otherId');
         $task = $this->_getTable('TaskTable')->getTaskById($taskId);

         return new ViewModel(array(
               'task' => $task
            ));
      }
   }

   public function boardViewMembersAction()
   {
      // Get members of a project
      $members = $this->_getTable('ViewUsersProjectsTable')->getUsersInProject($this->params('id'));

      // Get tasks in a project for each member
      $arrayTasksForMember = array();
      foreach($members as $member)
      {
         $arrayTasksForMember[$member->id] = array();
         $tasksForMember = $this->_getTable('ViewUsersTasksTable')->getTasksForMemberInProject($this->params('id'), $member->id);
         foreach($tasksForMember as $task)
            array_push($arrayTasksForMember[$member->id], $task);
      }

      $result = new ViewModel(array(
         'projectId'         => $this->params('id'),
         'members'           => $members,
         'tasksForMember'    => $arrayTasksForMember
      ));
      $result->setTerminal(true);

      return $result;
   }

   public function boardViewTasksAction()
   {
      // Get tasks in a project
      $tasks = $this->_getTable('TaskTable')->getAllTasksInProject($this->params('id'));

      // Get user(s) doing a task
      $arrayMembersForTask = array();
      foreach($tasks as $task)
      {
         $arrayMembersForTask[$task->id] = array();
         $membersForTask = $this->_getTable('ViewTasksUsersTable')->getUsersAffectedOnTask($task->id);
         foreach($membersForTask as $member)
            array_push($arrayMembersForTask[$task->id], $member);
      }

      $result = new ViewModel(array(
         'projectId'         => $this->params('id'),
         'tasks'             => $tasks,
         'membersForTask'    => $arrayMembersForTask
      ));
      $result->setTerminal(true);

      return $result;
   }

   public function assignTaskAction()
   {
      $sessionUser = new container('user');
      $projectId = $this->params('id');
      $data = $this->getRequest()->getPost();

      if($this->_userIsAdminOfProject($sessionUser->id, $projectId))
      {

         if($this->_userIsAssignToTask($data['targetMemberId'], $data['taskId']))
         {
            return $this->getResponse()->setContent(json_encode(array(
               'hasRightToAssignTask' => true,
               'alreadyAssigned'      => true
            )));
         }
         else
         {
            $this->_getTable('UsersTasksAffectationsTable')->addAffectation($data['targetMemberId'], $data['taskId']);

            return $this->getResponse()->setContent(json_encode(array(
               'hasRightToAssignTask' => true,
               'alreadyAssigned'      => false
            )));
         }
      }
      else
      {
         return $this->getResponse()->setContent(json_encode(array(
            'hasRightToAssignTask' => false,
            'alreadyAssigned'      => false
         )));
      }
   }

   public function moveTaskAction()
   {
      $sessionUser = new container('user');
      $projectId = $this->params('id');
      // Get POST data
      $data = $this->getRequest()->getPost();

      // Check if current user has rights to move the task
      if($this->_userIsAdminOfProject($sessionUser->id, $projectId)
        || $this->_getTable('UsersTasksAffectationsTable')->getAffectation($sessionUser->id, $data['taskId']))
      {
         $this->_getTable('TaskTable')->updateStateOfTask($data['taskId'], $data['targetSection']);

         if($data['oldMemberId'] != $data['targetMemberId'])
         {
            $this->_getTable('UsersTasksAffectationsTable')->updateTaskAffectation($data['oldMemberId'], $data['taskId'], $data['targetMemberId']);
         }

         // If task was successfully moved, add a task's movement event.
         // First of all, get right event type, moved task's name and old/new task's user's name.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
         $name = $this->_getTable("TaskTable")->getTaskById($data['taskId'])->name;
         $oldUsername = $this->_getTable("UserTable")->getUserById($data['oldMemberId'])->username;
         $newUsername = $this->_getTable("UserTable")->getUserById($data['targetMemberId'])->username;
         // Then add the new event in the database.
         $message = "<u>" . $sessionUser->username . "</u> moved task <font color=\"#FF6600\">" . $name . "</font> from <font color=\"#995527\">(" . $oldUsername . ", " . $data['oldSection'] . ")</font> to <font color=\"#995527\">(" . $newUsername . ", " . $data['targetSection'] . ")</font>.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         // Link the new event to the current project.
         $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event1 = $this->_getTable("ViewEventTable")->getEvent($eventId, false);
         // For the task's news feed.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Info")->id;
         $message = "\"" . $sessionUser->username . "\" moved the task from \"(" . $oldUsername . ", " . $data['oldSection'] . ")\" to \"(" . $newUsername . ", " . $data['targetSection'] . ")\".";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         $this->_getTable("EventOnTaskTable")->add($eventId, $data['taskId']);
         // Get SYSTEM user's ID and link it to the new task's event.
         $systemUserId = $this->_getTable("UserTable")->getSystemUser()->id;
         $this->_getTable("EventUserTable")->add(($systemUserId ? $systemUserId : $sessionUser->id), $eventId);
         $event2 = $this->_getTable("ViewEventTable")->getEvent($eventId, true);

         // Send task's event socket.
         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data.
            $client->setParameterPost(array(
               "requestType"  => "newEvent",
               "event"       => json_encode($event2)
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }

         // Send back data and project's event socket's data.
         return $this->getResponse()->setContent(json_encode(array(
            'taskId'              => $data['taskId'],
            'targetMemberId'      => $data['targetMemberId'],
            'targetSection'       => $data['targetSection'],
            'event'               => $event1,
            'hasRightToMoveTask'  => true
         )));
      }
      else
      {
         return $this->getResponse()->setContent(json_encode(array(
            'hasRightToMoveTask'  => false
         )));
      }
   }

   public function unassignTaskAction()
   {
      $projectId = $this->params('id');
      $sessionUser = new container('user');
      $data = $this->getRequest()->getPost();
      $resMessage = 'Unassign success';

      if($this->_userIsAdminOfProject($sessionUser->id, $projectId))
      {
         $this->_getTable('UsersTasksAffectationsTable')->deleteAffectation($data['userId'], $data['taskId']);
      }
      else
         $resMessage = 'You do not have rights to unassign this task !';

      return $this->getResponse()->setContent(json_encode(array(
         'message' => $resMessage
      )));
   }

   public function deleteTaskAction()
   {
      $projectId = $this->params('id');
      $sessionUser = new container('user');
      $taskId = $this->params('otherId');
      $resMessage = 'Delete success';


      if($this->_userIsAdminOfProject($sessionUser->id, $projectId))
      {
         // Get old task's data for the historical.
         $oldTaskData = $this->_getTable('TaskTable')->getTaskById($taskId);
         $this->_getTable('TaskTable')->deleteTask($taskId);

         // If task was successfully deleted, add a task's deletion event.
         // First of all, get right event type.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
         // Then add the new event in the database.
         $message = "<u>" . $sessionUser->username . "</u> deleted task <font color=\"#FF6600\">" . $oldTaskData->name . "</font>.";
         // This event have some details.
         // TODO: use priority with BasicEnum.
         $priorityArray = ['High', 'Medium', 'Low'];
         $details =
            "<table class='eventDetailsTable'>
               <tr>
                  <th class='eventDetailsTaskAttribute'></th>
                  <th>Deleted task's values</th>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Name: </td>
                  <td>" . $oldTaskData->name . "</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Deadline: </td>
                  <td>" . $oldTaskData->deadLineDate . "</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Duration: </td>
                  <td>" . $oldTaskData->durationsInHours . "h</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Priority: </td>
                  <td>" . $priorityArray[$oldTaskData->priorityLevel - 1] . "</td>
               </tr>
               <tr>
                  <td class='eventDetailsTaskAttribute'>Description: </td>
                  <td>" . (empty($oldTaskData->description) ? "-" : $oldTaskData->description) . "</td>
               </tr>
            </table>";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId, $details);
         // Link the new event to the current project.
         $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event = $this->_getTable("ViewEventTable")->getEvent($eventId, false);

         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data for the project page.
            $client->setParameterPost(array(
               "requestType"  => "newEvent",
               "event"        => json_encode($event)
            ));
            // Send HTTP request to server.
            $response = $client->send();
            // Send a delete request to inform users which are currently in the task page.
            $client->setParameterPost(array(
               "requestType"  => "taskDeleted",
               "taskId"       => $taskId,
               "username"     => $sessionUser->username
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }
      }
      else
      {
         $resMessage = 'You do not have rights to delete this task !';
      }


      return $this->getResponse()->setContent(json_encode(array(
         'message' => $resMessage
      )));
   }

   public function addMemberAction()
   {
      $sessionUser = new container('user');
      $projectId = $this->params('id');

      if($this->_userIsAdminOfProject($sessionUser->id, $projectId))
      {
         $request = $this->getRequest();

         if($request->isPost())
         {
            // Get right event type.
            $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Users")->id;

            foreach ($_POST as $value)
            {
               if($value != 'isManager')
               {
                  $isManager = isset($_POST['is-manager-'.$value]) ? true : false;
                  $this->_getTable('ProjectsUsersMembersTable')->addMemberToProject($value, $this->params('id'), $isManager);
                  // If member was successfully added, add an event.
                  // Get new member's username.
                  $addedMemberName = $this->_getTable("UserTable")->getUserById($value)->username;
                  // Then add the new event in the database.
                  $message = "<u>" . $sessionUser->username . "</u> added user <u>" . $addedMemberName . "</u> in project.";
                  $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
                  // Link the new event to the current project.
                  $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
                  // Finaly link the new event to the user who created it.
                  $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
                  // Get event's data to send them to socket server.
                  $event = $this->_getTable("ViewEventTable")->getEvent($eventId, false);

                  try
                  {
                     // Make an HTTP POST request to the event's server so he can broadcast a
                     // new websocket related to the new event.
                     $client = new Client('http://127.0.0.1:8002');
                     $client->setMethod(Request::METHOD_POST);
                     // Setting POST data.
                     $client->setParameterPost(array(
                        "requestType"  => "newEvent",
                        "event"        => json_encode($event)
                     ));
                     // Send HTTP request to server.
                     $response = $client->send();
                  }
                  catch (\Exception $e)
                  {
                     error_log("WARNING: could not connect to events servers. Maybe offline?");
                  }
               }
            }

            $this->redirect()->toRoute('project', array(
                'id' => $projectId
            ));


         }

         $usersNotMemberOfProject = $this->_getUsersNotMemberOfProject($this->params('id'));

         return new ViewModel(array(
            'users' => $usersNotMemberOfProject
         ));
      }
      else
      {
         $this->redirect()->toRoute('project', array(
             'id' => $projectId
         ));
      }
   }

   public function removeMemberAction()
   {
      $sessionUser = new container('user');
      $projectId = $this->params('id');
      $memberId = $this->params('otherId');

      if($this->_userIsAdminOfProject($sessionUser->id, $projectId))
      {
         if($memberId != $sessionUser->id)
         {
            // Remove from project
            $this->_getTable('ProjectsUsersMembersTable')->removeMember($memberId, $projectId);

            // Remove all affectations in the project
            // Foreach tasks in the project, if the user is assigned we delete it
            $tasks = $this->_getTable('TaskTable')->getAllTasksInProject($projectId);
            foreach($tasks as $task)
            {
               $this->_getTable('UsersTasksAffectationsTable')->deleteAffectation($memberId, $task->id);
            }
         }
      }

      $this->redirect()->toRoute('project', array(
          'id' => $projectId
      ));
   }

   public function detailsAction()
   {
      $sessionUser = new container('user');
      $id = (int)$this->params('id');
      $projectDetails = $this->_getTable('ViewProjectDetailsTable')->getProjectDetails($id, $sessionUser->id);
      $tempMembers = $this->_getTable('ViewProjectsMembersSpecializationsTable')->getProjectMembers($id);
      $members = array();
      $i = 0;

      // Struct the members array.
      foreach ($tempMembers as $tmpM)
      {
         // Indicate whether the current member already exists in the members
         // list or not.
         // If yes, we just have to add the object's specialization to the
         // existing specializations of the user.
         $alreadyExisting = false;
         $nbCurrentMembers = count($members);

         // Check if the current member already exists.
         for ($j = 0; $j < $nbCurrentMembers; ++$j)
         {
            // Add the specialization to the specializations list.
            if ($tmpM->username == $members[$j]["username"])
            {
               $alreadyExisting = true;
               $members[$j]["specializations"][] = (empty($tmpM->specialization) ? "-" : $tmpM->specialization);
               break;
            }
         }

         // If the current member is not already existing in the members list,
         // add it.
         if (!$alreadyExisting)
         {
            $members[$i]["username"] = $tmpM->username;
            $members[$i]["specializations"][] = empty($tmpM->specialization) ? "-" : $tmpM->specialization;
            $members[$i]["isAdmin"] = $tmpM->isAdmin;
            ++$i;
         }
      }

      // Send the success message back with JSON.
      return new JsonModel(array(
         'success' => true,
         'projectDetails' => $projectDetails,
         'members'   => $members
      ));
   }

   public function postNewsFeedAction()
   {
      $request = $this->getRequest();
      if ($request->isPost())
      {
         $sessionUser = new container('user');
         // Get request's parameters.
         $taskId = (int)$_POST['taskId'];
         $eventText = $_POST["text"];
         $typeId = $_POST["typeId"];

         // Add new data in the database.
         // First of all, add the new event in the database.
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $eventText, $typeId);
         // Link the new event to the current project.
         $this->_getTable("EventOnTaskTable")->add($eventId, $taskId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event = $this->_getTable("ViewEventTable")->getEvent($eventId, true);

         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data.
            $client->setParameterPost(array(
               "requestType"  => "newEvent",
               "event"        => json_encode($event)
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }

         // Send the success message back with JSON.
         return new JsonModel(array(
            'success' => true
         ));
      }
      else
      {
         // Send the success message back with JSON.
         return new JsonModel(array(
            'success' => false
         ));
      }
   }

   private function _userIsAssignToTask($userId, $taskId)
   {
      return !empty($this->_getTable('UsersTasksAffectationsTable')->getAffectation($userId, $taskId));
   }

   private function _userIsAdminOfProject($userId, $projectId)
   {
      return $this->_getTable('ViewProjectMinTable')->userIsAdminOfProject($userId, $projectId);
   }

   private function _getUsersNotMemberOfProject($projectId)
   {
      $members = $this->_getTable('ViewUsersProjectsTable')->getUsersInProject($projectId)->buffer();
      $users = $this->_getTable('UserTable')->getAllUsers()->buffer();

      $notMembersArray = array();
      foreach($users as $user)
      {
         // Don't show the SYSTEM user.
         if ($user->username != "SYSTEM")
         {
            $mustAdd = true;

            foreach($members as $member)
            {
               if($user->id == $member->id)
               $mustAdd = false;
            }

            if($mustAdd)
               array_push($notMembersArray, $user);
         }
      }

      return $notMembersArray;
   }
}


?>
