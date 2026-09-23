Microsoft 365 Copilot Response Generator v0.2.0
This osTicket plugin creates a Microsoft 365 Copilot analysis and stores it as an internal ticket note.
Important change in v2
JavaScript and CSS are injected inline by the plugin. No browser assets are loaded from `include/plugins`, avoiding Apache 403 rules that protect the osTicket include directory.
Installation
Extract the folder `m365-copilot-response-generator` under `include/plugins/`.
Add and enable the plugin in the osTicket admin panel.
Configure Tenant ID, Client ID, Client Secret and Redirect URI.
Register the exact Redirect URI as a Web redirect URI in Microsoft Entra ID.
Add the delegated Graph permissions requested by the plugin and grant admin consent.
Open a ticket and select Microsoft 365 Copilot from the More menu.
Redirect URI example
`https://support.example.no/scp/ajax.php/m365-copilot/callback`
Requirements
PHP cURL extension
PHP OpenSSL extension
Database account allowed to create the plugin token table
Microsoft 365 Copilot license for each technician using the API
Syntax check
Run before enabling:
bash
find include/plugins/m365-copilot-response-generator -name "*.php" -exec php -l {} \;
