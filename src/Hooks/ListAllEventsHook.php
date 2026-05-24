<?php

namespace Diversworld\CalendarEditorBundle\Hooks;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\PageModel;
use Contao\FrontendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ListAllEventsHook
{
    public function __construct(
        private readonly CheckAuthService $checkAuthService,
        private readonly ContaoFramework  $framework,
        private readonly Security         $security,
        private readonly LoggerInterface  $logger,
    )
    {
    }

    #[AsHook('getAllEvents')]
    public function updateAllEvents(array $events, array $arrCalendars): array
    {
        if (empty($arrCalendars)) {
            return $events;
        }

        $user = $this->security->getUser();
        if (!$user instanceof FrontendUser) {
            return $events;
        }

        $calendarObjects = [];
        $isUserAdminForCalendar = [];
        $isUserMemberForCalendar = [];
        $jumpPages = [];

        /** @var CalendarModelEdit $calendarModelEditAdapter */
        $calendarModelEditAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $calendarModels = $calendarModelEditAdapter->findByIds($arrCalendars);

        if (null === $calendarModels) {
            return $events;
        }

        /** @var PageModel $pageModelAdapter */
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        foreach ($calendarModels as $calendarModel) {
            $currentPid = $calendarModel->id;
            $calendarObjects[$currentPid] = $calendarModel;
            $jumpPages[$currentPid] = '';

            if ($calendarModel->AllowEdit === '1') {
                $isUserAdminForCalendar[$currentPid] = $this->checkAuthService->isUserAdmin($calendarModel, $user);
                $isUserMemberForCalendar[$currentPid] = $this->checkAuthService->isUserAuthorized($calendarModel, $user);

                $page = $pageModelAdapter->findByPk($calendarModel->caledit_jumpTo);
                if ($page !== null) {
                    $jumpPages[$currentPid] = $page->getFrontendUrl();
                }
            } else {
                $isUserAdminForCalendar[$currentPid] = false;
                $isUserMemberForCalendar[$currentPid] = false;
            }
        }

        foreach ($events as &$date) {
            foreach ($date as &$timestamp) {
                foreach ($timestamp as &$event) {
                    $pid = $event['pid'];
                    if (
                        isset($calendarObjects[$pid]) &&
                        $calendarObjects[$pid]->AllowEdit === '1' &&
                        $this->checkAuthService->areEditLinksAllowed(
                            $calendarObjects[$pid],
                            $event,
                            (int)($user->id ?? 0),
                            $isUserAdminForCalendar[$pid] ?? false,
                            $isUserMemberForCalendar[$pid] ?? false
                        )
                    ) {
                        $this->addEditLinks($event, $jumpPages[$pid]);
                    }
                }
            }
        }

        return $events;
    }

    private function addEditLinks(array &$event, string $url): void
    {
        $event['editRef'] = $url . '?edit=' . $event['id'];
        $event['editLabel'] = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
        $event['editTitle'] = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];
    }
}
