<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\BackendTemplate;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\Config;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\StringUtil;
use Contao\ModuleEventlist;
use Contao\PageModel;
use Contao\System;
use Psr\Log\LoggerInterface;

class ModuleHiddenEventlist extends ModuleEventlist
{
    private LoggerInterface $logger;
    /**
     * Current date object
     * @var integer
     */
    protected $Date;

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_eventlist';

    protected static $table = 'tl_calendar_events';

    /**
     * ScopeMatcher Service
     * @var ScopeMatcher
     */
    private $scopeMatcher;

    public function isBackend(): bool
    {
        if ($this->scopeMatcher === null) {
            $this->scopeMatcher = System::getContainer()->get('contao.routing.scope_matcher');
        }

        return $this->scopeMatcher->isBackendRequest(System::getContainer()->get('request_stack')->getCurrentRequest());
    }

    public function isFrontend(): bool
    {
        if ($this->scopeMatcher === null) {
            $this->scopeMatcher = System::getContainer()->get('contao.routing.scope_matcher');
        }

        return $this->scopeMatcher->isFrontendRequest(System::getContainer()->get('request_stack')->getCurrentRequest());
    }

    public static function findCurrentUnPublishedByPid(int $pid, int $start, int $end, array $options = [])
    {
        $t = static::$table;
        $start = intval($start);
        $end = intval($end);

        $arrColumns = array("$t.pid=? AND $t.published!='1' AND (($t.startTime>=$start AND $t.startTime<=$end) OR ($t.endTime>=$start AND $t.endTime<=$end) OR ($t.startTime<=$start AND $t.endTime>=$end) OR ($t.recurring='1' AND ($t.recurrences=0 OR $t.repeatEnd>=$start) AND $t.startTime<=$end))");

        if (!isset($options['order'])) {
            $options['order'] = "$t.startTime";
        }

        return CalendarEventsModel::findBy($arrColumns, $pid, $options);
    }

    protected function getAllEvents($arrCalendars, $intStart, $intEnd, $blnFeatured = null)
    {
        $this->logger = System::getContainer()->get('monolog.logger.contao.general');

        if (!is_array($arrCalendars)) {
            return array();
        }

        $this->arrEvents = array();

        foreach ($arrCalendars as $id) {
            $strUrl = $this->strUrl;
            $objCalendar = CalendarModel::findByPk($id);

            // Get the current "jumpTo" page
            if ($objCalendar !== null && $objCalendar->jumpTo && ($objTarget = $objCalendar->getRelated('jumpTo')) !== null) {
                /** @var PageModel $objTarget */
                // Prüfen, ob ein Editor-Ziel (caledit_jumpTo) definiert ist
                if ($objCalendar !== null && $objCalendar->caledit_jumpTo) {
                    $objEditorPage = PageModel::findByPk($objCalendar->caledit_jumpTo);

                    if ($objEditorPage !== null) {
                        $strUrl = $objEditorPage->getFrontendUrl();
                    } else {
                        $this->logger->error('ERROR: Keine gültige Editor-Seite (caledit_jumpTo) konfiguriert.');
                    }
                } else {
                    // Fallback: Alte Mechanik (jumpTo) verwenden
                    if ($objCalendar !== null && $objCalendar->jumpTo && ($objTarget = $objCalendar->getRelated('jumpTo')) !== null) {
                        $strUrl = $objTarget->getFrontendUrl((Config::get('useAutoItem') && !Config::get('disableAlias')) ? '/%s' : '/events/%s');
                    } else {
                        $strUrl = ''; // Sicherstellen, dass $strUrl initialisiert ist
                        $this->logger->error('ERROR: Weder eine Editor-Seite noch eine Standard-Zielseite definiert.');
                    }
                }
            }

            $objEvents = $this->findCurrentUnPublishedByPid($id, $intStart, $intEnd);

            if ($objEvents === null) {
                continue;
            }

            while ($objEvents->next()) {
                $editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'] ?? 'Bearbeiten';
                $editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'] ?? 'Event bearbeiten';

                // Bearbeitungslinks generieren und hinzufügen
                $editUrl = $strUrl . '?edit=' . $objEvents->id;
                $objEvents->editRef = $editUrl; // Variable hinzufügen
                $objEvents->editLabel = $editLabel;
                $objEvents->editTitle = $editTitle;

                $this->addEvent($objEvents, $objEvents->startTime, $objEvents->endTime, $strUrl, $intStart, $intEnd, $id);

                // Recurring events
                if ($objEvents->recurring) {
                    $count = 0;
                    $arrRepeat = StringUtil::deserialize($objEvents->repeatEach);

                    while ($objEvents->endTime < $intEnd) {
                        if ($objEvents->recurrences > 0 && $count++ >= $objEvents->recurrences) {
                            break;
                        }

                        $arg = $arrRepeat['value'];
                        $unit = $arrRepeat['unit'];

                        if ($arg < 1) {
                            break;
                        }

                        $strtotime = '+ ' . $arg . ' ' . $unit;

                        $objEvents->startTime = strtotime($strtotime, $objEvents->startTime);
                        $objEvents->endTime = strtotime($strtotime, $objEvents->endTime);

                        // Skip events outside the scope
                        if ($objEvents->endTime < $intStart || $objEvents->startTime > $intEnd) {
                            continue;
                        }

                        $this->addEvent($objEvents, $objEvents->startTime, $objEvents->endTime, $strUrl, $intStart, $intEnd, $id);
                    }
                }
            }
        }

        // Sort data
        foreach (array_keys($this->arrEvents) as $key) {
            ksort($this->arrEvents[$key]);
        }

        // HOOK: modify result set
        if (isset($GLOBALS['TL_HOOKS']['getAllEvents']) && is_array($GLOBALS['TL_HOOKS']['getAllEvents'])) {
            foreach ($GLOBALS['TL_HOOKS']['getAllEvents'] as $callback) {
                $this->import($callback[0]);
                $this->arrEvents = $this->{$callback[0]}->{$callback[1]}($this->arrEvents, $arrCalendars, $intStart, $intEnd, $this);
            }
        }

        return $this->arrEvents;
    }


    /**
     * Display a wildcard in the back end
     * @return string
     */
    public function generate()
    {
        if ($this->isBackend() ) {
            $objTemplate = new BackendTemplate('be_wildcard');

            $objTemplate->wildcard = '### UNPULISHED EVENT LIST ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

            return $objTemplate->parse();
        }

        $this->cal_calendar = $this->sortOutProtected($this->cal_calendar);
        $this->cal_calendar = unserialize($this->cal_calendar);

        // Return if there are no calendars
        if (!is_array($this->cal_calendar) || count($this->cal_calendar) < 1) {
            return '';
        }

        //return parent::generate();
        $result = parent::generate();
        return $result;
    }

    /**
     * Generate module
     */
    protected function compile()
    {
        parent::compile();
    }
}

?>
