<?php
require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(__DIR__ . '/../../api/Microsoft365TokenManager.php');
class MicrosoftAuthAjaxController extends AjaxController {
    private function config() {
        $cfg=AIResponseGeneratorPlugin::getActiveConfig();
        if (!$cfg) throw new RuntimeException('Plugin not configured.');
        return $cfg;
    }
    private function manager($cfg) {
        return new Microsoft365TokenManager($cfg->get('tenant_id'),$cfg->get('client_id'),$cfg->get('client_secret'),$cfg->get('redirect_uri'));
    }
    function login() {
        global $thisstaff;
        $this->staffOnly();
        $manager=$this->manager($this->config());
        Http::redirect($manager->getAuthorizationUrl((int)$thisstaff->getId()));
    }
    function callback() {
        global $thisstaff;
        $this->staffOnly();
        $manager=$this->manager($this->config());
        if (!empty($_GET['error'])) throw new RuntimeException('Microsoft sign-in error: '.($_GET['error_description'] ?? $_GET['error']));
        if (!$manager->validateState($_GET['state'] ?? '')) throw new RuntimeException('Invalid OAuth state.');
        $staffId=$manager->getPendingStaffId();
        if (!$staffId || $staffId !== (int)$thisstaff->getId()) throw new RuntimeException('OAuth staff session mismatch.');
        if (empty($_GET['code'])) throw new RuntimeException('Authorization code is missing.');
        $manager->exchangeAuthorizationCode($staffId,$_GET['code']);
        $target=ROOT_PATH.'scp/index.php';
        Http::redirect($target);
    }
}
