<?php
require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.thread.php');
require_once(__DIR__ . '/../../api/Microsoft365CopilotClient.php');
require_once(__DIR__ . '/../../api/Microsoft365TokenManager.php');

class AIAjaxController extends AjaxController {
    function generate() {
        global $thisstaff;
        $this->staffOnly();
        $ticketId=(int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticketId || !($ticket=Ticket::lookup($ticketId))) return Http::response(404,$this->encode(array('ok'=>false,'error'=>__('Unknown ticket'))));
        $role=$ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY)) return Http::response(403,$this->encode(array('ok'=>false,'error'=>__('Access denied'))));
        $cfg=null; $iid=(int)($_POST['instance_id'] ?? 0);
        if ($iid) { $all=AIResponseGeneratorPlugin::getAllConfigs(); if (isset($all[$iid])) $cfg=$all[$iid]; }
        if (!$cfg) $cfg=AIResponseGeneratorPlugin::getActiveConfig();
        if (!$cfg) return Http::response(500,$this->encode(array('ok'=>false,'error'=>__('Plugin not configured'))));
        try {
            $manager=new Microsoft365TokenManager($cfg->get('tenant_id'),$cfg->get('client_id'),$cfg->get('client_secret'),$cfg->get('redirect_uri'));
            $token=$manager->getAccessToken((int)$thisstaff->getId());
            if (!$token) return $this->encode(array('ok'=>false,'auth_required'=>true,'auth_url'=>'ajax.php/m365-copilot/login','error'=>__('Microsoft 365 sign-in is required.')));
            $copilot=new Microsoft365CopilotClient($token,'Europe/Oslo');
            $conversationId=$copilot->createConversation();
            $reply=trim($copilot->sendMessage($conversationId,$this->buildPrompt($ticket,$cfg),((string)$cfg->get('web_search')==='1')));
            if ($reply==='') throw new RuntimeException('Empty response from Microsoft 365 Copilot.');
            $note=$this->createInternalNote($ticket,$reply,$thisstaff,$cfg);
            return $this->encode(array('ok'=>true,'text'=>$reply,'note_created'=>true,'note_id'=>is_object($note)&&method_exists($note,'getId')?(int)$note->getId():0));
        } catch (Throwable $e) { return $this->encode(array('ok'=>false,'error'=>$e->getMessage())); }
    }

    private function buildPrompt(Ticket $ticket,$cfg) {
        $instruction=trim((string)$cfg->get('system_prompt'));
        if (!$instruction) $instruction='Du er en senior servicedesk-tekniker. Analyser saken, svar på norsk og ikke finn på informasjon.';
        $parts=array("INSTRUKSJONER:
".$instruction,"SAKSINFORMASJON:
Saksnummer: ".$ticket->getNumber()."
Emne: ".$ticket->getSubject());
        $recent=array(); $thread=$ticket->getThread();
        if ($thread) foreach ($thread->getEntries() as $entry) { $recent[]=$entry; if(count($recent)>20) array_shift($recent); }
        $history=array();
        foreach($recent as $entry) {
            $type=(string)$entry->getType(); $label=$type==='M'?'Kunde':($type==='R'?'Agent':($type==='N'?'Internt notat':'Melding'));
            $poster=$entry->getPoster(); $name=is_object($poster)&&method_exists($poster,'getName')?$poster->getName():(string)$poster;
            $body=$this->plainText((string)$entry->getBody());
            if($body!=='') $history[]='['.$label.': '.$name."]
".$body;
        }
        if($history) $parts[]="SAKSHISTORIKK:
".implode("

",$history);
        $rag=trim((string)$cfg->get('rag_content')); if($rag!=='') $parts[]="EKSTRA KUNNSKAPSGRUNNLAG:
".mb_substr($rag,0,20000,'UTF-8');
        $parts[]="OPPGAVE:
Returner kun følgende struktur:

ANALYSE:
...

FORSLAG TIL LØSNING:
...

INFORMASJON SOM MANGLER:
...

FORSLAG TIL KUNDESVAR:
...";
        return implode("

",$parts);
    }

    private function createInternalNote(Ticket $ticket,$reply,$staff,$cfg) {
        $thread=$ticket->getThread(); if(!$thread) throw new RuntimeException('Ticket thread could not be loaded.');
        $title=trim((string)$cfg->get('note_title')); if(!$title) $title='Microsoft 365 Copilot løsningsforslag';
        $vars=array('title'=>$title,'note'=>new TextThreadEntryBody($reply),'poster'=>$staff->getName(),'staffId'=>(int)$staff->getId(),'userId'=>0,'source'=>'Microsoft 365 Copilot');
        $errors=array(); $entry=$thread->addNote($vars,$errors);
        if(!$entry) throw new RuntimeException('Unable to create internal note: '.implode('; ',array_map('strval',$errors)));
        return $entry;
    }

    private function plainText($body) {
        $body=preg_replace('/<br\s*\/?>/i',"
",$body);
        $body=preg_replace('/<\/(p|div|li|tr|h[1-6])>/i',"
",$body);
        $body=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return trim(preg_replace("/
{3,}/","

",str_replace(array("
",""),"
",$body)));
    }
}
