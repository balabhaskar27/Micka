<?php

namespace App\Model;

use Nette;


class RecordModel extends \BaseModel
{
    private $recordMd = NULL;
    private $recordMdValues = [];
    private $typeTableMd = 'edit_md';
    private $profil_id = -1;
    private $package_id = -1;
    
	public function startup()
	{
		parent::startup();
	}
    
    private function setRecordMdById($id, $typeTableMd, $right)
    {
        $this->typeTableMd = $typeTableMd == 'edit_md' ? 'edit_md' : 'md';
        if ($this->typeTableMd == 'edit_md') {
            $this->recordMd = $this->db->query(
                "SELECT * FROM edit_md WHERE sid=? AND uuid=?",session_id(),$id)->fetch();
        } else {
            $this->recordMd = $this->db->query(
                "SELECT * FROM md WHERE uuid=?", $id)->fetch();
        }
        if ($this->recordMd && $right != 'new') {
            if ($this->isRight2MdRecord($right) === FALSE) {
                $this->recordMd = NULL;
            }
        }
        return;
    }
    
    private function setRecordMdValues()
    {
        if ($this->recordMd && count($this->recordMdValues) == 0) {
            $table = $this->typeTableMd == 'edit_md' ? 'edit_md_values' : 'md_values';
            $this->recordMdValues = $this->db->query(
                "SELECT * FROM $table WHERE recno=?", $this->recordMd->recno)->fetchAll();
        }
        return;
    }
    
    private function isRight2MdRecord($right)
    {
        if ($this->recordMd === NULL) {
            return FALSE;
        }
        if ($this->user->isInRole('admin')) {
            return TRUE;
        }
        switch ($right) {
            case 'read':
                if($this->recordMd->data_type > 0) {
                    return TRUE;
                }
                if($this->user->isLoggedIn()) {
                    if($this->recordMd->create_user == $this->user->getIdentity()->username) {
                        return TRUE;
                    }
                    if($this->user->isLoggedIn()) {
                        foreach ($this->user->getIdentity()->data['groups'] as $row) {
                            if ($row == $this->recordMd->view_group) {
                                return TRUE;
                            }
                            if ($row == $this->recordMd->edit_group) {
                                return TRUE;
                            }
                        }
                    }
                }
                return FALSE;
            case 'write':
                if($this->user->isLoggedIn()) {
                    if($this->recordMd->create_user == $this->user->getIdentity()->username) {
                        return TRUE;
                    }
                    if($this->user->isLoggedIn()) {
                        foreach ($this->user->getIdentity()->data['groups'] as $row) {
                            if ($row == $this->recordMd->edit_group) {
                                return TRUE;
                            }
                        }
                    }
                }
                return FALSE;
            default:
                return FALSE;
        }
    }
    
    private function getUuid()
    {
        $uuid = new \UUID;
        $uuid->generate();
        return $uuid->toRFC4122String();
    }

    private function getNewRecno($typeTableMd)
    {
        $table = $typeTableMd == 'edit_md' ? 'edit_md' : 'md';
        $recno = $this->db->query("SELECT MAX(recno)+1 FROM $table")->fetchField();
        return $recno > 1 ? $recno : 1;
    }
    
    private function seMdValues($data)
    {
        if (count($data) > 0) {
            $this->db->query("INSERT INTO edit_md_values", $data);
        }
    }
    
    private function updateEditMD($recno)
    {
        /*
		'data_type';
		'edit_group';
		'view_group';
        'last_update_user';
        'last_update_date';
        'x1';
        'y1';
        'x2';
        'y2';
        'the_geom geometry';
        'range_begin';
        'range_end';
        'md_update';
        'title';
        'valid';
        'prim';
        */
        $xml = $this->recordMd->pxml == '' ? NULL : str_replace("'", "&#39;", $this->recordMd->pxml);
        $this->db->query("UPDATE edit_md SET pxml=XMLPARSE(DOCUMENT ?) 
                WHERE sid='".session_id()."' AND recno=?", $xml, $recno);
    }
    
    private function setEditMd2Md($editRecno, $recno)
    {
        $mdRecno = NULL;
        if ($recno == 0) {
            $mdRecno = $this->getNewRecno('md');
            $this->db->query("
                INSERT INTO md (recno,uuid,md_standard,lang,data_type,create_user,create_date,edit_group,view_group,x1,y1,x2,y2,the_geom,range_begin,range_end,md_update,title,server_name,pxml,valid)
                SELECT ?,uuid,md_standard,lang,data_type,create_user,create_date,edit_group,view_group,x1,y1,x2,y2,the_geom,range_begin,range_end,md_update,title,server_name,pxml,valid
                FROM edit_md WHERE recno=?"
                , $mdRecno, $editRecno);
        } else {
            $sql = "UPDATE md SET pxml=edit.pxml FROM edit_md edit
                    WHERE edit.recno=? AND md.recno=? AND edit.sid='".session_id()."'";
            $this->db->query($sql,$editRecno,$recno);
        }
        return $mdRecno;
    }
    
    private function deleteMdValues($recno) {
        $this->db->query("DELETE FROM md_values WHERE recno=?", $recno);
        return;
    }
    
    private function deleteEditMdValuesByProfil($editRecno, $mds, $profil_id, $package_id) {
        $sql = "DELETE FROM edit_md_values WHERE recno=?";
        if ($profil_id > -1) {
            $sql .= " AND md_id IN(SELECT md_id FROM profil WHERE profil_id=$profil_id)";
        }
        if ($package_id > -1) {
            $sql .= " AND package_id=$package_id";
        }
		if ($mds == 0 || $mds == 10) {
			$sql .= " AND md_id<>38";
		}
        $this->db->query($sql, $editRecno);
        return;
    }

    private function setEditMdValues2MdValues($editRecno, $recno)
    {
        $this->db->query("
            INSERT INTO md_values (recno, md_id, md_value, md_path, lang , package_id)
            SELECT ?, md_id, md_value, md_path, lang , package_id 
            FROM edit_md_values WHERE recno=?"
            , $recno, $editRecno);
        return;
    }

    public function setEditRecord2Md()
    {
        if (!$this->recordMd || $this->typeTableMd != 'edit_md') {
            // error
        }
        $editRecno = $this->recordMd->recno;
        $this->setRecordMdById($this->recordMd->uuid, 'md', 'write');
        if ($this->recordMd) {
            $this->deleteMdValues($this->recordMd->recno);
            $this->setEditMdValues2MdValues($editRecno, $this->recordMd->recno);
            $this->setEditMd2Md($editRecno, $this->recordMd->recno);
        } else {
            $recno = $this->setEditMd2Md($editRecno, 0);
            $this->setEditMdValues2MdValues($editRecno, $recno);
        }
    }

    private function setNewEditMdRecord($post)
    {
        $data['sid'] = session_id();
		$data['recno'] = $this->getNewRecno('edit_md');
        $data['uuid'] = $this->getUuid();
		$data['md_standard'] = isset($post['standard']) ? $post['standard'] : 0;
        $data['lang'] = isset($post['standard']) ? $post['standard'] : 0;
		$data['data_type'] = -1;
		$data['create_user'] = $this->user->identity->username;
		$data['create_date'] = Date("Y-m-d");
        $data['edit_group'] = isset($post['group_e']) ? $post['group_e'] : $this->user->identity->username;
        $data['view_group'] = isset($post['group_v']) ? $post['group_v'] : $this->user->identity->username;
        $lang_main = (isset($post['lang_main']) && $post['lang_main'] != '') ? $post['lang_main'] : 'eng';
        $data['lang'] = isset($post['languages']) ? implode($post['languages'],"|") : '';
        if ($data['lang'] == '' && $lang_main != '') {
            $data['lang'] = $lang_main;
        }
        $this->db->query("INSERT INTO edit_md", $data);
		if ($data['md_standard'] == 0 || $data['md_standard'] == 10) {
            $this->db->query("INSERT INTO edit_md_values", [
                [
                'recno'=>$data['recno'],
                'md_value'=>$data['uuid'],
                'md_id'=>38,
                'md_path'=>'0_0_38_0',
                'lang'=>'xxx',
                'package_id'=>0
                ], [
                'recno'=>$data['recno'],
                'md_value'=>$lang_main,
                'md_id'=>5527,
                'md_path'=>'0_0_39_0_5527_0',
                'lang'=>'xxx',
                'package_id'=>0
                ], [
                'recno'=>$data['recno'],
                'md_value'=>Date("Y-m-d"),
                'md_id'=>44,
                'md_path'=>'0_0_44_0',
                'lang'=>'xxx',
                'package_id'=>0
                ]
            ]);
		}
        $this->setRecordMdById($data['uuid'], 'edit_md','new');
        $this->recordMd->pxml = $this->xmlFromRecordMdValues();
        $this->applyXslTemplate2Xml('micka2one19139.xsl');
        $this->updateEditMD($this->recordMd->recno);
        return;
    }
    
    public function findMdById($id, $typeTableMd, $right)
    {
        $this->setRecordMdById($id, $typeTableMd, $right);
        return $this->recordMd;
    }
    
    public function getRecordMd()
    {
        return $this->recordMd;
    }
    
    public function getRecordMdValues()
    {
        $this->setRecordMdValues();
        return $this->recordMdValues;
    }
    
	public function deleteEditRecords()
	{
        $this->db->query('DELETE FROM edit_md_values WHERE recno IN(SELECT recno FROM edit_md WHERE sid=?)', session_id());
        $this->db->query('DELETE FROM edit_md WHERE sid=?', session_id());
        return;
	}
    
    public function getMdTitle($lang)
    {
        if ($this->recordMd === NULL) {
            return '';
        }
        $title_app = '';
        $title_eng = '';
        $title_other = '';
        foreach ($this->recordMdValues as $row) {
            if ($row->md_id == 11) {
                $title_other = $row->md_value;
                if ($row->lang == $lang) {
                    $title_app = $row->md_value;
                }
                if ($row->lang == 'eng') {
                    $title_eng = $row->md_value;
                }
            }
        }
        if ($title_app != '') {
            $rs = $title_app;
        } elseif ($title_eng != '') {
            $rs = $title_eng;
        } else {
            $rs = $title_other;
        }
        return $rs;
    }

    public function copyMd2EditMd($mode='edit')
    {
        $this->deleteEditRecords();
        $id = '';
        if ($this->recordMd) {
            if ($mode == 'clone') {
                if ($this->isRight2MdRecord('read')) {
                    $id = $this->getUuid();
                    $editRecno = $this->getNewRecno('edit_md');
                    $this->db->query("
                        INSERT INTO edit_md (sid,recno,uuid,md_standard,lang,data_type,create_user,create_date,edit_group,view_group,x1,y1,x2,y2,the_geom,range_begin,range_end,md_update,title,server_name,pxml,valid)
                        SELECT ?,?,?,md_standard,lang,-1,?,?,?,?,x1,y1,x2,y2,the_geom,range_begin,range_end,md_update,title,server_name,pxml,valid
                        FROM md WHERE recno=?"
                        , session_id(), $editRecno, $id, $this->user->identity->username, Date("Y-m-d"),
                            $this->user->identity->username, $this->user->identity->username, $this->recordMd->recno);
                    $this->db->query("
                        INSERT INTO edit_md_values (recno, md_id, md_value, md_path, lang , package_id)
                        SELECT ?, md_id, md_value, md_path, lang , package_id 
                        FROM md_values WHERE recno=?"
                        , $editRecno, $this->recordMd->recno);
                }
            } elseif ($this->isRight2MdRecord('write')) {
                $id = $this->recordMd->uuid;
                $editRecno = $this->getNewRecno('edit_md');
                $this->db->query("
                    INSERT INTO edit_md (sid,recno,uuid,md_standard,lang,data_type,create_user,create_date,edit_group,view_group,x1,y1,x2,y2,the_geom,range_begin,range_end,md_update,title,server_name,pxml,valid)
                    SELECT ?,?,uuid,md_standard,lang,data_type,create_user,create_date,edit_group,view_group,x1,y1,x2,y2,the_geom,range_begin,range_end,md_update,title,server_name,pxml,valid
                    FROM md WHERE recno=?"
                    , session_id(), $editRecno, $this->recordMd->recno);
                $this->db->query("
                    INSERT INTO edit_md_values (recno, md_id, md_value, md_path, lang , package_id)
                    SELECT ?, md_id, md_value, md_path, lang , package_id 
                    FROM md_values WHERE recno=?"
                    , $editRecno, $this->recordMd->recno);
            }
        }
        return $id;
    }
    
	public function deleteMdById($id)
	{
        $this->setRecordMdById($id, 'md', 'write');
        if ($this->recordMd) {
            $this->db->query("DELETE FROM md_values WHERE recno =?", $this->recordMd->recno);
            $this->db->query("DELETE FROM md WHERE recno=?", $this->recordMd->recno);
        }
        return;
	}
    
    public function createNewMdRecord($post)
    {
        $this->deleteEditRecords();
        return $this->setNewEditMdRecord($post);
    }
    
    private function getMdValuesFromForm($formData, $appLang)
    {
        $editMdValues = [];
		foreach ($formData as $key => $value) {
			if ( $key == 'nextpackage' ||
				 $key == 'nextprofil' ||
				 $key == 'afterpost' ||
				 $key == 'data_type' ||
				 $key == 'edit_group' ||
				 $key == 'view_group' ||
				 $key == 'uuid' ||
				 $key == 'ende') {
				continue;
			}
			if ($key == 'package') {
                $this->package_id = is_numeric($value) ? (int)$value : -1;
				continue;
			}
			if ($key == 'profil') {
                $this->profil_id =  is_numeric($value) ? (int) $value : -1;
				continue;
			}
			if ($value != '') {
				if (strpos($key, 'RB_') !== FALSE) {
					continue;
				}
				$pom = explode('|', $key);
				//form_code|lang|package_id|md_path
				if (count($pom) != 4) {
					continue;
				}
                if ($pom[0] == 'R') {
                    continue;
                }
                if ($pom[0] == 'D' && $appLang == 'cze') {
                    // ISO date
                    $value = dateCz2Iso($value);
                }
                $data = array();
                $data['recno'] = $this->recordMd->recno;
                $data['md_value'] = trim($value);
                $data['md_id'] = getMdIdFromMdPath($pom[3]);
                $data['md_path'] = $pom[3];
                $data['lang'] = $pom[1];
                $data['package_id'] = $pom[2];
                if ($data['recno'] != '' &&
                        $data['md_value'] != '' &&
                        $data['md_id'] != '' &&
                        $data['md_path'] != '' &&
                        $data['lang'] != '' &&
                        $data['package_id'] != '') {
                    array_push($editMdValues, $data);
                }
			}
		}
		return $editMdValues;
    }


    public function setFormMdValues($id, $post, $appLang)
    {
        $mdr = $this->findMdById($id, 'edit_md', 'edit');
        if (!$mdr) {
            // Error
        }
        if (array_key_exists('fileIdentifier_0_TXT', $post)) {
            // Micka Lite
    		require(__DIR__ . '/CswClient.php');
        	require(__DIR__ . '/lite/resources/Kote.php');
            require(__DIR__ . '/micka_lib_php5.php');
            $cswClient = new CSWClient();
            $input = Kote::processForm(beforeSaveRecord($post));
            $params = Array('datestamp'=>date('Y-m-d'), 'lang'=>'cze');
            $xmlstring = $cswClient->processTemplate($input, WWW_DIR . '/lite/resources/kote2iso.xsl', $params);
            $importer = new MetadataImport();
            $importer->setTableMode('tmp');
            $md = array();
            $md['file_type'] = 'WMS';
            $md['edit_group'] = MICKA_USER;
            $md['view_group'] = MICKA_USER;
            $md['mds'] = 0;
            $md['lang'] = 'cze';
            $lang_main = 'cze';
            $md['update_type'] = 'lite';
            $report = $importer->import(
                            $xmlstring,
                            'WMS',
                            MICKA_USER,
                            $md['edit_group'],
                            $md['view_group'],
                            $md['mds']=0, // co to je?
                            $md['lang'], // co to je?
                            $lang_main,
                            $params=false,
                            $md['update_type']
            );
        } else {
            $editMdValues = $this->getMdValuesFromForm($post, $appLang);
            $this->deleteEditMdValuesByProfil(
                    $this->recordMd->recno,
                    $this->recordMd->md_standard, 
                    $this->profil_id, 
                    $this->package_id);
            $this->seMdValues($editMdValues);
            $this->recordMd->pxml = $this->xmlFromRecordMdValues();
            $this->applyXslTemplate2Xml('micka2one19139.xsl');
            $this->updateEditMD($this->recordMd->recno);
        }
        return;
    }
    
	private function getIdElements()
    {
        // Move to CodeListModel
        $data = $this->db->query("SELECT standard_schema.md_id, standard_schema.md_standard, elements.el_name
            FROM elements JOIN standard_schema ON (elements.el_id = standard_schema.el_id)")->fetchAll();
		$rs = [];
        foreach ($data as $row) {
			$mds = $row->md_standard;
			$id = $row->md_id;
			$rs[$mds][$id] = $row->el_name;
		}
		return $rs;
	}
    
    private function xmlFromRecordMdValues()
    {
        if (!$this->recordMd) {
            return '';
        }
        $this->setRecordMdValues();
        $elements_label = $this->getIdElements();
		$eval_text = '';
        $i = 0;
        $mds = $this->recordMd->md_standard == 10 ? 0 : $this->recordMd->md_standard;
		foreach ($this->recordMdValues as $row) {
            $path_arr = explode('_', substr($row->md_path, 0, strlen($row->md_path) - 1));
            $eval_text_tmp = '$vysl';
            foreach ($path_arr as $key=>$value) {
                if ($key%2 == 0) {
                    $eval_text_tmp .= "['" . $elements_label[$mds][$value] . "']";
                }
                else {
                    $eval_text_tmp .= '[' . $value . ']';
                }
            }
            if ($row->md_id == 4742) {
                $eval_text_value = $eval_text_tmp . "['lang'][$i]['@value']=" . '"' . gpc_addslashes($row->md_value) . '";' . "\n";
                $eval_text_atrrib = $eval_text_tmp . "['lang'][$i]['@attributes']['code']=" . '"' . $row->lang . '";' . "\n";
                $i++;
                $eval_text_tmp = $eval_text_value . $eval_text_atrrib;
            } elseif ($row->lang != 'xxx') {
                $eval_text_value = $eval_text_tmp . "['lang'][$i]['@value']=" . '"' . gpc_addslashes($row->md_value) . '";' . "\n";
                $eval_text_atrrib = $eval_text_tmp . "['lang'][$i]['@attributes']['code']=" . '"' . $row->lang . '";' . "\n";
                $i++;
                $eval_text_tmp = $eval_text_value . $eval_text_atrrib;
            } else {
                $eval_text_tmp .= '="' . gpc_addslashes($row->md_value) . '";' . "\n";
            }
            $eval_text .= $eval_text_tmp;
        }
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['uuid']='".rtrim($this->recordMd->uuid)."';\n";
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['langs']='".(substr_count($this->recordMd->lang,'|')+1)."';\n";
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['updated']='".$this->recordMd->create_date."';\n";
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['x1']='".$this->recordMd->x1."';\n";
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['x2']='".$this->recordMd->x2."';\n";
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['y1']='".$this->recordMd->y1."';\n";
        $eval_text .= '$vysl' . "['".$elements_label[$mds][0]."'][0]['@attributes']['y2']='".$this->recordMd->y2."';\n";
        eval ($eval_text);
		$xml = \Array2XML::createXML('rec', $vysl);
        //echo $xml->saveXML(); die;
        return $xml->saveXML();
    }
    
    private function applyXslTemplate2Xml($xsltemplate)
    {
        $xml = $this->recordMd->pxml;
        if ($xsltemplate != '' && $xml != '') {
            $xml = applyTemplate($xml, $xsltemplate, $this->user->identity->username);
            if ($xml != '') {
                $this->recordMd->pxml = $xml;
            }
        }
		return;
    }
}
