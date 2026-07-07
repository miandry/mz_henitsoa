<?php
namespace Drupal\mz_henitsoa;

class ServiceHenitsoa  {

        /**
   * Constructs a new GenerateService object.
    */
    public function __construct() {

    }

    public function checkifEcolageExist($inscript_id , $mois_id ){
      $item = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(
        [
            'type' => 'ecolage',
            'field_mois' => $mois_id,
            'field_inscrit' => $inscript_id
        ]);

      if(!empty($item)){return true;}  
      return false ;
    }
    public function checkifInscrExist($el_id , $annee){
        $item = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(
          [
              'type' => 'inscription',
              'field_eleve' => $el_id,
              'field_annee_scolaire' => $annee
          ]);
  
        if(!empty($item)){return true;}  
        return false ;
      }

    public function getFullName($eleve){
       return $eleve->field_nom->value ." ".$eleve->field_prenom->value ;
    }  

    public function checkEcolage($ins , $node_ecolage){
        $tid = $node_ecolage->field_mois->target_id ;
        $current_values = $ins->get('field_ecolage_status')->getValue();
        $current_values[] = $tid ;
       $ins->field_ecolage_status = $current_values ;
       $ins->save();
    }

    public function unCheckEcolage($ins , $node_ecolage){
        $tid = $node_ecolage->field_mois->target_id ;
        $current_values = $ins->get('field_ecolage_status')->getValue();
        foreach($current_values as $key => $item){
            if($tid == $item['target_id'] ){
                unset($current_values[$key]);
            }
        }
        $ins->field_ecolage_status = $current_values ;
        $ins->save();
    }
      


}

