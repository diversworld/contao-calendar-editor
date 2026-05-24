<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\Template;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\SecurityBundle\Security;

#[AsFrontendModule(category: 'calendar', template: 'mod_event_ReaderEditLink')]
class ModuleEventReaderEdit extends AbstractFrontendModuleController
{
    /**
     * @var ModuleModel|null
     */
    protected $model;

    public function __construct(
        private CheckAuthService|ModuleModel|null $checkAuthService = null,
        private ContaoFramework|string|null       $framework = null,
        private ?Security                         $security = null,
        ModuleModel|null                          $model = null,
    )
    {
        if ($this->checkAuthService instanceof ModuleModel) {
            $model = $this->checkAuthService;
            $this->checkAuthService = null;
        }

        if (is_string($this->framework)) {
            $this->framework = null;
        }

        if ($model !== null) {
            $this->model = $model;
        }

        $this->initializeServices();
    }

    protected function initializeServices(): void
    {
        if ($this->checkAuthService instanceof CheckAuthService && $this->framework instanceof ContaoFramework) {
            return;
        }

        $container = System::getContainer();
        $this->checkAuthService = $container->get('caledit.service.auth');
        $this->framework = $container->get('contao.framework');
        $this->security = $container->get('security.helper');
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        // Return if no event has been specified
        if (!$request->query->has('auto_item') && !($request->attributes->has('_route_params') && isset($request->attributes->get('_route_params')['auto_item']))) {
            return new Response('');
        }

        $autoItem = $request->query->get('auto_item') ?: $request->attributes->get('_route_params')['auto_item'];

        $calendars = StringUtil::deserialize($model->cal_calendar);

        // Return if there are no calendars
        if (!is_array($calendars) || count($calendars) < 1) {
            return new Response('');
        }

        $template->editRef = '';
        $headline = StringUtil::deserialize($model->headline);
        $template->headline = is_array($headline) ? $headline['value'] : $model->headline;
        $template->hl = $model->hl ?: 'h1';
        $user = $this->security->getUser();

        if (!$user instanceof FrontendUser) {
            $user = null;
        }

        /** @var CalendarEventsModel $calendarEventsModelAdapter */
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        $hasBackendUser = $this->security->isGranted('ROLE_USER'); // Simplified for now, or use proper Contao role

        // Get current event
        if (!$hasBackendUser) {
            $objEvent = $calendarEventsModelAdapter->findPublishedByParentAndIdOrAlias($autoItem, $calendars);
        } else {
            $objEvent = $calendarEventsModelAdapter->findByIdOrAlias($autoItem);
            if ($objEvent !== null && !in_array($objEvent->pid, $calendars)) {
                $objEvent = null;
            }
        }

        if ($objEvent === null) {
            $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
            $template->error_class = 'error';
            return $template->getResponse();
        }

        /** @var CalendarModelEdit $calendarModelEditAdapter */
        $calendarModelEditAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $calendarModel = $calendarModelEditAdapter->findByPk($objEvent->pid);

        if ($calendarModel === null) {
            return new Response('');
        }

        if ($calendarModel->AllowEdit === '1') {
            $isUserAuthorized = $this->checkAuthService->isUserAuthorized($calendarModel, $user);
            $isUserAdmin = $this->checkAuthService->isUserAdmin($calendarModel, $user);
            $authorizedElapsedEvents = $this->checkAuthService->isUserAuthorizedElapsedEvents($calendarModel, $user);
            $areEditLinksAllowed = $this->checkAuthService->areEditLinksAllowed($calendarModel, $objEvent->row(), $user ? (int)$user->id : 0, $isUserAdmin, $isUserAuthorized);

            if ($areEditLinksAllowed) {
                /** @var PageModel $pageModelAdapter */
                $pageModelAdapter = $this->framework->getAdapter(PageModel::class);
                $objPage = $pageModelAdapter->findByPk($calendarModel->caledit_jumpTo);

                if ($objPage !== null) {
                    $strUrl = $objPage->getFrontendUrl();

                    $template->editRef = $strUrl . '?edit=' . $objEvent->id;
                    $template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
                    $template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

                    if ($model->caledit_showCloneLink) {
                        $template->cloneRef = $strUrl . '?clone=' . $objEvent->id;
                        $template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
                        $template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
                    }
                    if ($model->caledit_showDeleteLink) {
                        $template->deleteRef = $strUrl . '?delete=' . $objEvent->id;
                        $template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
                        $template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
                    }
                }
            } else {
                if (!$isUserAuthorized) {
                    $template->error_class = 'error';
                    $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_UnauthorizedUser'];
                    return $template->getResponse();
                }

                if ($objEvent->disable_editing) {
                    $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_DisabledEvent'];
                    $template->error_class = 'error';
                } else {
                    if (!$authorizedElapsedEvents) {
                        $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoPast'];
                    } else {
                        $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_OnlyUser'];
                    }
                    $template->error_class = 'error';
                }
            }
        } else {
            $template->error_class = 'error';
            $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
        }

        return $template->getResponse();
    }
}
