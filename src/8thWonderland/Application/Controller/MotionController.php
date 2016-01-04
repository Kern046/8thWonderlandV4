<?php

namespace Wonderland\Application\Controller;

use Wonderland\Library\Controller\ActionController;
use Wonderland\Library\Http\Response\Response;
use Wonderland\Library\Http\Response\JsonResponse;

class MotionController extends ActionController
{
    public function newAction()
    {
        $this->checkAccess('citizenship');

        return $this->render('motions/new', [
            'themes' => $this->application->get('motion_manager')->getMotionThemes(),
            'identity' => $this->getUser()->getIdentity(),
            'avatar' => $this->getUser()->getAvatar(),
            'nb_unread_messages' => $this->application->get('message_manager')->countUnreadMessages($this->getUser()->getId()),
        ]);
    }

    public function createAction()
    {
        $this->checkAccess('citizenship');

        $motion = $this->application->get('motion_manager')->createMotion(
            $this->request->get('title'),
            $this->request->get('description'),
            $this->request->get('theme'),
            $this->getUser(),
            $this->request->get('means')
        );

        return new JsonResponse($motion, 201);
    }

    public function showAction()
    {
        $motionManager = $this->application->get('motion_manager');
        $motionId = $this->request->get('motion_id', null, 'int');

        return $this->render('motions/show', [
            'motion' => $motionManager->getMotion($motionId),
            'has_already_voted' => $motionManager->hasAlreadyVoted($motionId, $this->getUser()->getId()),
            'identity' => $this->getUser()->getIdentity(),
            'avatar' => $this->getUser()->getAvatar(),
            'nb_unread_messages' => $this->application->get('message_manager')->countUnreadMessages($this->getUser()->getId()),
        ]);
    }

    public function voteAction()
    {
        $this->application->get('motion_manager')->voteMotion(
            $this->getUser(),
            $this->request->get('motion_id', null, 'int'),
            $this->request->get('vote', null, 'bool')
        );

        return new Response();
    }
}
