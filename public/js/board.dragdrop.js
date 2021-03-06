$(document).ready(function() {

   var tasks = document.querySelectorAll('.board-task');
   for (var i = 0, n = tasks.length; i < n; i++) {
      tasks[i].draggable = true;
   };

   var board = [].concat(Array.prototype.slice.call(document.getElementsByClassName('board')), Array.prototype.slice.call(document.getElementsByClassName('listed-task')));
   var hideMe;
   var oldTarget;
   var isAffectation;
   for (var i in board) {
      board[i].onselectstart = function(e) {
         e.preventDefault();
      }
      board[i].ondragstart = function(e) {
         hideMe = e.target;
         oldTarget = e.target.parentNode;
         isAffectation = e.target.type === 'unassigned-task' ? true : false;
         e.dataTransfer.setData('board-task', e.target.id);
         e.dataTransfer.effectAllowed = 'move';
      };
      board[i].ondragend = function(e) {
         e.target.style.visibility = 'visible';
      };
      var lastEneterd;
      board[i].ondragenter = function(e) {

         if (hideMe) {
            hideMe.style.visibility = 'hidden';
            hideMe = null;
         }
         // Save this to check in dragleave.
         lastEntered = e.target;

         var sectionName = isAffectation ? 'board' : 'board-section';
         var section = closestWithClass(e.target, sectionName);

         if(section) {
            section.classList.add('droppable');
            e.preventDefault(); // Not sure if these needs to be here. Maybe for IE?
            return false;
         }

      };
      board[i].ondragover = function(e) {
         // TODO: Check data type.
         // TODO: Check that it's not the original section.
         if (closestWithClass(e.target, 'board-section')) {
            e.preventDefault();
         }
      };
      board[i].ondragleave = function(e) {
         // FF is raising this event on text nodes so only check elements.
         if (e.target.nodeType === 1) {
            // dragleave for outer elements can trigger after dragenter for inner elements
            // so make sure we're really leaving by checking what we just entered.
            // relatedTarget is missing in WebKit: https://bugs.webkit.org/show_bug.cgi?id=66547
            var sectionName = isAffectation ? 'board' : 'board-section';
            var section = closestWithClass(e.target, sectionName);
            section.classList.remove('droppable');
         }
         lastEntered = null; // No need to keep this around.
      };
      board[i].ondrop = function(e) {
         var section = closestWithClass(e.target, 'board-section');
         var id = e.dataTransfer.getData('board-task');
         var task = document.getElementById(id);

         if(isAffectation) {
            var taskId = task.getAttribute('task-id');
            var targetMemberId = $(e.target.parentNode).closest('[member-id]').attr('member-id');
            var targetSection = $(e.target).closest('[section]').attr('section');


            $.post("/project/" + projectId + "/assignTask", {
               taskId: taskId,
               targetMemberId: targetMemberId,
               targetSection: targetSection
            })
            .done(function(data) {
               var data = JSON.parse(data);

               if(!data.hasRightToAssignTask) {
                  addBootstrapAlert('board-alert-container', 'You do not have the right to move this task because you are either not manager.', 'danger');
               }
               if(data.alreadyAssigned) {
                  addBootstrapAlert('board-alert-container', 'Already assigned.', 'danger');
               }

               $('#board-container').load(window.location.href + '/boardViewMembers');
            });
         }
         else {
            if (id) {
               if (task) {
                  if (section !== task.parentNode) {

                     var taskId = task.getAttribute('task-id');
                     var oldMemberId = oldTarget.parentNode.getAttribute('member-id');
                     var oldSection = oldTarget.getAttribute('section');
                     var targetMemberId = $(e.target.parentNode).closest('[member-id]').attr('member-id');
                     var targetSection = $(e.target).closest('[section]').attr('section');
                     var isManager = $('#hidden').attr('is-manager');

                     if(targetMemberId !== oldMemberId)
                     {
                        bootbox.confirm('Are sure you want to assign this task to another member ?', function(result) {
                           if(result === true) {
                              moveTask(taskId, oldMemberId, oldSection, targetMemberId, targetSection, task);
                              section.appendChild(task);
                           }
                        });
                     }
                     else
                     {
                        moveTask(taskId, oldMemberId, oldSection, targetMemberId, targetSection, task);
                        section.appendChild(task);
                     }

                  }
               } else {
                  alert('couldn\'t find task #' + id);
               }
            }
         }

         section.classList.remove('droppable');
         e.preventDefault();
      };
   }

   function moveTask(taskId, oldMemberId, oldSection, targetMemberId, targetSection) {
      $.post("/project/" + projectId + "/moveTask", {
         taskId: taskId,
         oldMemberId: oldMemberId,
         oldSection : oldSection,
         targetMemberId: targetMemberId,
         targetSection: targetSection
      })
      .done(function(data) {
         var data = JSON.parse(data);

         if(!data.hasRightToMoveTask) {
            addBootstrapAlert('board-alert-container', 'You do not have the right to move this task because you are either not manager or this task is not assigned to you.', 'danger');

            $('#board-container').load(window.location.href + '/boardViewMembers');
         }
      });


   }

   function closestWithClass(target, className) {
      while (target) {
         if (target.nodeType === 1 &&
            target.classList.contains(className)) {
            return target;
         }
         target = target.parentNode;
      }
      return null;
   }
   $('.users').removeClass('droppable');
});
