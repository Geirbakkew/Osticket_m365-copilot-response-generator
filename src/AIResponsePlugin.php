<?php
/*********************************************************************
 * Microsoft 365 Copilot Response Generator Plugin
 *********************************************************************/
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(__DIR__ . '/Config.php');

class AIResponseGeneratorPlugin extends Plugin {
    var $config_class = 'AIResponseGeneratorPluginConfig';
    private static $active_config = null;
    private static $configs = array();

    function bootstrap() {
        Signal::connect('ticket.view.more', array($this, 'onTicketViewMore'), 'Ticket');
        Signal::connect('object.view', array($this, 'onObjectView'), 'Ticket');
        Signal::connect('ajax.scp', array($this, 'onAjaxScp'));

        $cfg = $this->getConfig();
        if ($cfg) {
            self::$active_config = $cfg;
            $inst = $cfg->getInstance();
            if ($inst && $inst->getId()) {
                self::$configs[$inst->getId()] = $cfg;
            }
        }
    }

    public static function getActiveConfig() {
        return self::$active_config;
    }

    public static function getAllConfigs() {
        return self::$configs;
    }

    function onTicketViewMore($ticket, &$data) {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isStaff()) return;
        if (!$ticket || !method_exists($ticket, 'getId')) return;

        static $rendered = array();
        foreach (self::getAllConfigs() as $iid => $cfg) {
            if (isset($rendered[$iid])) continue;
            $rendered[$iid] = true;
            $inst = $cfg->getInstance();
            $name = $inst ? $inst->getName() : ('Instance ' . $iid);
            ?>
            <li>
                <a class="m365-copilot-generate" href="#"
                   data-ticket-id="<?php echo (int)$ticket->getId(); ?>"
                   data-instance-id="<?php echo (int)$iid; ?>">
                    <i class="icon-magic"></i>
                    <?php echo __('Microsoft 365 Copilot'); ?> - <?php echo Format::htmlchars($name); ?>
                </a>
            </li>
            <?php
        }
    }

    function onObjectView($object, &$data) {
        static $included = false;
        if ($included || !($object instanceof Ticket)) return;
        $included = true;
        ?>
        <style type="text/css">
            .m365-copilot-busy { opacity: .6; pointer-events: none; }
        </style>
        <script type="text/javascript">
        window.M365Copilot = window.M365Copilot || {};
        window.M365Copilot.ajaxEndpoint = 'ajax.php/m365-copilot/response';

        (function($) {
            $(document)
                .off('click.m365copilot', '.m365-copilot-generate')
                .on('click.m365copilot', '.m365-copilot-generate', function(e) {
                    e.preventDefault();
                    var $link = $(this);
                    if ($link.data('busy')) return;

                    var original = $link.html();
                    $link.data('busy', true)
                        .addClass('m365-copilot-busy')
                        .text('Copilot arbeider...');

                    $.ajax({
                        url: window.M365Copilot.ajaxEndpoint,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            ticket_id: $link.data('ticket-id'),
                            instance_id: $link.data('instance-id')
                        }
                    }).done(function(result) {
                        if (result && result.auth_required && result.auth_url) {
                            window.location.href = result.auth_url;
                            return;
                        }
                        if (result && result.ok) {
                            alert('Copilot-forslaget er lagret som internt notat.');
                            window.location.reload();
                            return;
                        }
                        alert(result && result.error ? result.error : 'Ukjent feil fra Copilot-pluginen.');
                    }).fail(function(xhr) {
                        alert('HTTP-feil: ' + xhr.status + ' ' + xhr.statusText);
                    }).always(function() {
                        $link.data('busy', false)
                            .removeClass('m365-copilot-busy')
                            .html(original);
                    });
                });
        })(jQuery);
        </script>
        <?php
    }

    function onAjaxScp($dispatcher) {
        require_once(__DIR__ . '/controllers/AIAjax.php');
        require_once(__DIR__ . '/controllers/MicrosoftAuthAjax.php');
        $dispatcher->append(url_post('^/m365-copilot/response$', array('AIAjaxController', 'generate')));
        $dispatcher->append(url_get('^/m365-copilot/login$', array('MicrosoftAuthAjaxController', 'login')));
        $dispatcher->append(url_get('^/m365-copilot/callback$', array('MicrosoftAuthAjaxController', 'callback')));
    }
}
