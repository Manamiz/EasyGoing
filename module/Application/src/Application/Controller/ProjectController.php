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

// Project controller ; will be calling when the user access the "easygoing/project" page.
// Be careful about the class' name, which must be the same as the file's name.
class ProjectController extends AbstractActionController
{
   public function indexAction()
   {
      return new ViewModel(array(
            'id'       => $this->params('id'),
            'description' => 'Description projet'
         ));
   }

   public function taskAction()
   {
      return new ViewModel(array(
            'id' => $this->params('id')
         ));
   }

   public function addTaskAction()
   {
      return new ViewModel();
   }

   public function editTaskAction()
   {
      return new ViewModel();
   }

   public function deleteTaskAction()
   {

   }

   public function addMemberAction()
   {
      return new ViewModel();
   }

   public function removeMemberAction()
   {

   }

   public function loadEventAction()
   {

   }

   public function detailsAction()
   {
       $id = (int)$this->params('id');
       

       // Send the success message back with JSON.
       $result = new JsonModel(array(
		    'success' => true,
			'message' => $id
       ));

       return $result;
   }
}


?>
