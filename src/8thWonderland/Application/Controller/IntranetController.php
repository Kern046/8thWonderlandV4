<?php

namespace Wonderland\Application\Controller;

use Wonderland\Library\Controller\ActionController;

use Wonderland\Application\Model\Member;
use Wonderland\Application\Model\Mailer;

use Wonderland\Library\Admin\Log;

class IntranetController extends ActionController {
    public function indexAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        $this->viewParameters['translate'] = $this->application->get('translate');
        
        $select_geo = false;
        $member = $this->application->get('member_manager')->getMember($id);
        $db = $this->application->get('mysqli');

        // Teste si le code country du membre est valide
        // =============================================
        $country_ok = $db->count('country', " WHERE code='{$member->getCountry()}'");
        if ($country_ok === 0) {
            $select_geo = true;
        } else {
            $region_member = $member->getRegion();
            if (!isset($region_member) || $region_member === -2) {
                $select_geo = true;
            }
        }

        if ($select_geo) {
            $this->displaySelectCountry($member);
        } else {
            $this->displayIntranet($member);
        }
    }
    
    /**
     * @param \Wonderland\Application\Model\Member $member
     */
    protected function displaySelectCountry(Member $member) {
        $countries = $member->listCountries();
        $this->viewParameters['select_country'] = '<option></option>';
        $nbCountries = count($countries);
        for ($i = 0; $i < $nbCountries; ++$i) {
            $this->viewParameters['select_country'] .= "<option value='{$countries[$i]['Code']}'>{$countries[$i][$member->langue]}</option>";
        }
        $this->viewParameters['msg'] = '';
        $this->viewParameters['default_view'] = 'members/select_country.view';
        $this->render('connected');
    }
    
    protected function displayIntranet(Member $member) {
        $session = $this->application->get('session');
        if (!empty($_POST['group_id'])) {
            $session->set('desktop', $_POST['group_id']);
        }
        $memberManager = $this->application->get('member_manager');
        // affichage du profil
        $this->viewParameters['identity'] = $member->getIdentity();
        $this->viewParameters['avatar'] = $member->getAvatar();
        $this->viewParameters['admin'] = $memberManager->isMemberInGroup($member->getId(), 1);

        $desktop = $session->get('desktop');
        if (isset($desktop)) {
            $this->viewParameters['Contact_Group'] = $memberManager->isContact($desktop);
            $this->viewParameters['haut_milieu'] =
                ($desktop === 1)
                ? VIEWS_PATH . 'admin/menu_admin.view'
                : VIEWS_PATH . 'groups/menu_groups.view'
            ;

            $this->viewParameters['milieu_droite'] = 
                "<table>" .
                "<tr><td id='md_section1'><script type='text/javascript'>window.onload=Clic('/Admin/displayStatsCountry', '', 'md_section1');</script></td></tr>" .
                "<tr><td id='md_section2'><script type='text/javascript'>window.onload=Clic('/Group/displayMembers', '', 'md_section2');</script></td></tr>" .
                "</table>"
            ;
            $this->viewParameters['milieu_milieu'] = "";
            $this->viewParameters['milieu_gauche'] = "<script type='text/javascript'>window.onload=Clic('/Member/displayContactsGroups', '', 'milieu_gauche');</script>";
        } else {
            $this->viewParameters['haut_milieu'] = VIEWS_PATH . 'members/menu.view';
            $this->viewParameters['milieu_droite'] = '';
            $this->viewParameters['milieu_milieu'] = "<script type='text/javascript'>window.onload=Clic('/Intranet/communicate', '', 'milieu_milieu');</script>";
            $this->viewParameters['milieu_gauche'] = "<script type='text/javascript'>window.onload=Clic('/Motion/displayMotionsInProgress', '', 'milieu_gauche');</script>";
            $this->viewParameters['list_motions'] = $this->application->get('motion_manager')->displayActiveMotions();

            // affichage des groupes du membre
            $this->viewParameters['list_groups'] = $this->application->get('group_manager')->getMemberGroups($member->getId());
            $this->viewParameters['milieu_droite'] = "<script type='text/javascript'>window.onload=Clic('/Group/displayGroupsMembers', '', 'milieu_droite');</script>";

        }
        if ($this->is_Ajax()) {
            $this->render('members/intranet');
        } else {
            $this->viewParameters['default_view'] = 'members/intranet.view';
            $this->render('connected');
        }
    }
    
    public function zoneGeoAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        
        if (!empty($_POST['country']) && isset($_POST['region']) && $_POST['region'] !== 0) {
            $db = $this->application->get('mysqli');
            $member = $this->application->get('member_manager')->getMember($id);
            
            $db->query(
                "UPDATE Utilisateurs SET Pays='{$_POST['country']}', Region={$_POST['region']} WHERE IDUser={$member->getId()}"
            );
            
            if ($_POST['region'] !== -1) {
                $logger = $this->application->get('logger');
                $logger->setWriter('db');
                // Ajout de l'utilisateur dans le groupe correspondant
                // ===================================================
                $echec_createGroup = false;
                $groupId = 0;
                $group_name = $db->select("SELECT Name FROM regions WHERE Region_id={$_POST['region']}");
                if ($db->count('Groups', " WHERE Group_name='{$db->real_escape_string($group_name[0]['Name'])}'") == 0) {
                    $db->query("INSERT INTO Groups (Group_Type, Description, Group_name, ID_Contact) VALUES (1, 'Groupe regional', '{$db->real_escape_string($group_name[0]['Name'])}', 5)");
                    if ($db->affected_rows == 0) {
                        $echec_createGroup = true;
                        // Journal de log
                        $logger->log("Création du groupe régional " . $group_name[0]['Name'] . " par l'utilisateur " . $member->getIdentity(), Log::ERR);
                    } else {
                        $groupId = $db->insert_id;
                    }
                } else {
                    $group = $db->select("SELECT Group_id FROM Groups WHERE Group_name='{$db->real_escape_string($group_name[0]['Name'])}'");
                    $groupId = $group[0]['Group_id'];
                }

                if (!$echec_createGroup) {
                    $db->query("INSERT INTO Citizen_Groups (Citizen_id, Group_id) VALUES ({$auth->getIdentity()}, $groupId)");
                    if ($db->affected_rows === 0) {
                        // Journal de log
                        $logger->log("Ajout de l'utilisateur {$member->identite} dans le groupe {$group_name[0]['Name']}", Log::ERR);
                    }
                }
            
            } else {
                // Si la region choisie est 'other' alors Brennan Waco reçoit un mail
                // ==================================================================
                $mail = new Mailer();
                $mail->addRecipient('waco.brennan@gmail.com','');
                $mail->addFrom('developpeurs@8thwonderland.com','');
                $mail->addSubject('regions inconnues','');
                $mail->html = 
                    "<table><tr><td>ID User : {$auth->getIdentity()} :<br/>====================</td></tr>" .
                    "<tr><td>{$_POST['country']}<br/></td></tr>" .
                    '<tr><td>Message :<br/>====================</td></tr></table>'
                ;
                $mail->send();
            }
            $this->redirect('Intranet/index');
        } else {
            $translate = $this->application->get('translate');
            $this->viewParameters['translate'] = $translate;
            $this->viewParameters['msg'] = $translate->translate('fields_empty');
            $this->display(json_encode([
                'status' => 2,
                'reponse' => $translate->translate('fields_empty')
            ]));
        }
    }
    
    public function list_regionsAction() {
        $res = '<option></option>';
        if (!empty($_POST['country'])) {
            $regions = $this
                ->application
                ->get('mysqli')
                ->select("SELECT Region_id, Name FROM regions WHERE Country='{$_POST['country']}' ORDER BY Name ASC")
            ;
            $nbRegions = count($regions);
            if ($nbRegions > 0) {
                for($i = 0; $i < $nbRegions; ++$i) {
                    $res .= "<option value='{$regions[$i]['Region_id']}'>" . htmlentities($regions[$i]['Name']) . "</option>";
                }
            } else {
                $res .= "<option value='-1'>Other</option>";
            }
        }
        $this->display($res);
    }

    public function infosAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        $this->viewParameters['translate'] = $this->application->get('translate');
        $this->render('informations/public_news');
    }

    public function shareAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        $this->viewParameters['translate'] = $this->application->get('translate');
        $this->render('admin/dev_inprogress');
    }

    public function communicateAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        $this->viewParameters['translate'] = $this->application->get('translate');
        $this->render('informations/public_news');
    }

    public function financeAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        $this->viewParameters['translate'] = $this->application->get('translate');
        $this->render('admin/dev_inprogress');
    }
    
    public function consoleAction() {
        if (($id = $this->application->get('session')->get('__id__')) === null) {
            $this->redirect('Index/index');
        }
        if (!$memberManager->isMemberInGroup($id, 1)) {
            $this->redirect('Intranet/index');
        }
        
        $member = $memberManager->getMember($id);
        $logger = $this->application->get('logger');
        $logger->setWriter('db');
        $logger->log($member->getIdentity() . " entre dans la console d'administration.", Log::INFO);
        
        $this->viewParameters['translate'] = $this->application->get('translate');
        $this->redirect('Admin/display_console');
    }
}