<?php
/*********************************************************************
 * Microsoft 365 Copilot Response Generator - Configuration
 *********************************************************************/
require_once(INCLUDE_DIR . 'class.forms.php');

class AIResponseGeneratorPluginConfig extends PluginConfig {
    function getFormOptions() {
        return array(
            'title' => __('Microsoft 365 Copilot Settings'),
            'instructions' => __('Configure delegated Microsoft Graph authentication.'),
        );
    }

    function getFields() {
        $fields = array();

        $fields['tenant_id'] = new TextboxField(array(
            'label' => __('Tenant ID'),
            'required' => true,
            'configuration' => array('size' => 80, 'length' => 64),
        ));

        $fields['client_id'] = new TextboxField(array(
            'label' => __('Client ID'),
            'required' => true,
            'configuration' => array('size' => 80, 'length' => 64),
        ));

        $fields['client_secret'] = new TextboxField(array(
            'label' => __('Client Secret'),
            'required' => true,
            'hint' => __('Use the secret VALUE, not the secret ID.'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['redirect_uri'] = new TextboxField(array(
            'label' => __('Redirect URI'),
            'required' => true,
            'hint' => __('Example: https://support.example.no/scp/ajax.php/m365-copilot/callback'),
            'configuration' => array('size' => 100, 'length' => 255),
        ));

        $fields['system_prompt'] = new TextareaField(array(
            'label' => __('Copilot Instructions'),
            'required' => false,
            'configuration' => array(
                'rows' => 8,
                'html' => false,
                'placeholder' => __('Du er en senior servicedesk-tekniker. Analyser saken, svar på norsk og ikke finn på informasjon.'),
            ),
        ));

        $fields['rag_content'] = new TextareaField(array(
            'label' => __('Additional Knowledge Context'),
            'required' => false,
            'configuration' => array('rows' => 12, 'html' => false),
        ));

        $fields['note_title'] = new TextboxField(array(
            'label' => __('Internal Note Title'),
            'required' => false,
            'default' => 'Microsoft 365 Copilot løsningsforslag',
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['web_search'] = new ChoiceField(array(
            'label' => __('Enable web grounding'),
            'required' => false,
            'default' => '0',
            'choices' => array('1' => __('Yes'), '0' => __('No')),
        ));

        return $fields;
    }
}
