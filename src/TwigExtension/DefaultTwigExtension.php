<?php

namespace Drupal\mz_henitsoa\TwigExtension;
use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;

/**
 * Class DefaultTwigExtension.
 */
class DefaultTwigExtension extends AbstractExtension {

        
   /**
    * {@inheritdoc}
    */
    public function getTokenParsers() {
      return [];
    }

   /**
    * {@inheritdoc}
    */
    public function getNodeVisitors() {
      return [];
    }

   /**
    * {@inheritdoc}
    */
    public function getFilters() {
      return [];
    }

   /**
    * {@inheritdoc}
    */
    public function getTests() {
      return [];
    }

   /**
    * {@inheritdoc}
    */
    public function getFunctions() {
      return [
        new TwigFunction('bulletin',['Drupal\mz_henitsoa\TwigExtension\DefaultTwigExtension', 'twig_bulletin']),
       
      ];
    }
    public static function twig_bulletin($inscription){
      $bulletins = []; 
      ///kint($inscription['field_notes']);
      if(isset($inscription['field_notes'])){
        $tr1 = $inscription['field_notes'];
        foreach ($tr1 as $item) {
          $note = $item['node'];
          $coeff = $note->field_coeffience->value ;
          $tid_mat = $note->field_matiere->target_id ;
          $mat = $note->field_matiere->entity->label() ;
          $bulletins[$tid_mat] = [
              'mat' =>  $mat ,
              'tr1' =>  $item['title'],
              'coef' =>  $coeff 
          ];
        }
      }
      if(isset($inscription['field_notes_2'])){
        $tr2 = $inscription['field_notes_2'];
        foreach ($tr2 as $item) {
          $note = $item['node'];
          $coeff = $note->field_coeffience->value ;
          $tid_mat = $note->field_matiere->target_id ;
          $mat = $note->field_matiere->entity->label() ;
          $item_tr2 = [
              'mat' =>  $mat ,
              'tr2' =>  $item['title'],
              'coef' =>  $coeff 
          ];
          $bulletins[$tid_mat]  = array_merge($bulletins[$tid_mat],$item_tr2);
        }
      }
      if(isset($inscription['field_notes_3'])){
        $tr3 = $inscription['field_notes_3'];
        foreach ($tr3 as $item) {
          $note = $item['node'];
          $coeff = $note->field_coeffience->value ;
          $tid_mat = $note->field_matiere->target_id ;
          $mat = $note->field_matiere->entity->label() ;
          $item_tr3 = [
              'mat' =>  $mat ,
              'tr3' =>  $item['title'],
              'coef' =>  $coeff 
          ];
          $bulletins[$tid_mat]  = array_merge($bulletins[$tid_mat],$item_tr3);
        }
      }  
      return $bulletins;
    }
   /**
    * {@inheritdoc}
    */
    public function getOperators() {
      return [];
    }

   /**
    * {@inheritdoc}
    */
    public function getName() {
      return 'mz_crud.twig.extension';
    }
   
}
