<?php

namespace DanielGausi\CalendarEditorBundle\Hooks;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\FrontendUser;
use Contao\System;
use DanielGausi\CalendarEditorBundle\Models\CalendarModelEdit;
use DanielGausi\CalendarEditorBundle\Services\CheckAuthService;
use Contao\Frontend;
use Symfony\Component\HttpFoundation\RequestStack;

class ListAllEventsHook extends Frontend
{
    protected string $strTemplate = '';

    private CheckAuthService $checkAuthService;
    private FrontendUser $user;

    public function __construct(CheckAuthService $checkAuthService, FrontendUser $user)
    {
        parent::__construct();
        $this->checkAuthService = $checkAuthService;
        $this->user = $user;
    }

    public function addEditLinks(array &$event, string $url): void
    {
        $event['editRef'] = $url . '?edit=' . $event['id'];
        $event['editLabel'] = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
        $event['editTitle'] = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];
    }

    /**
     * Manipulate the $events array generated by ModuleCalendar and ModuleEventlist.
     */
    public function updateAllEvents(array $events, array $arrCalendars): array
    {
        if (empty($arrCalendars)) {
            return $events;
        }

        $calendarObjects = [];            // Detailed authorization check
        $isUserAdminForCalendar = [];     // Admin status per calendar
        $isUserMemberForCalendar = [];    // Member status per calendar
        $jumpPages = [];                  // Edit links for calendars

        $calendarModels = CalendarModelEdit::findByIds($arrCalendars);
        foreach ($calendarModels as $calendarModel) {
            $currentPid = $calendarModel->id; // Parent-ID for events

            $calendarObjects[$currentPid] = $calendarModel;

            $jumpPages[$currentPid] = '';
            if ($calendarModel->AllowEdit) {
                // Admin and Member status checks
                $isUserAdminForCalendar[$currentPid] = $this->checkAuthService->isUserAdmin($calendarModel, $this->user);
                $isUserMemberForCalendar[$currentPid] = $this->checkAuthService->isUserAuthorized($calendarModel, $this->user);

                // Get the jump-to-Edit-page for this calendar
                $page = $this->Database->prepare("SELECT * FROM tl_page WHERE id=(SELECT caledit_jumpTo FROM tl_calendar WHERE id=?)")
                    ->limit(1)
                    ->execute($calendarModel->id);
                if ($page->numRows === 1) {
                    $jumpPages[$currentPid] = $this->generateFrontendUrl($page->row(), '');
                }
            } else {
                // No editing allowed
                $isUserAdminForCalendar[$currentPid] = false;
                $isUserMemberForCalendar[$currentPid] = false;
            }
        }

        // Process events and add edit links where appropriate
        foreach ($events as &$date) {
            foreach ($date as &$timestamp) {
                foreach ($timestamp as &$event) {
                    $pid = $event['pid'];
                    if (
                        $this->user->id !== null &&
                        $calendarObjects[$pid]->AllowEdit === '1' &&
                        $this->checkAuthService->areEditLinksAllowed(
                            $calendarObjects[$pid],
                            $event,
                            $this->user->id,
                            $isUserAdminForCalendar[$pid],
                            $isUserMemberForCalendar[$pid]
                        )
                    ) {
                        $this->addEditLinks($event, $jumpPages[$pid]);
                    }
                }
            }
        }
        return $events;
    }
}
