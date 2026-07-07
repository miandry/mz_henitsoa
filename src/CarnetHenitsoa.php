<?php

namespace Drupal\mz_henitsoa;

class CarnetHenitsoa
{

  //  composer require phpoffice/phpword
  public function carnet($inscription) {
    $file_path = $this->getTemplateFilePath();
    if (!$file_path) {
      \Drupal::messenger()->addMessage("Template carnet.docx introuvable. Configurez le chemin dans l'application.", 'error');
      return FALSE;
    }

    $parser = \Drupal::service('entity_parser.manager');
    $inscription = $parser->node_parser($inscription);
    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($file_path);
    $eleve = $parser->node_parser($inscription['field_eleve']['nid']);

    $field_carnet = ['field_annee_scolaire', 'field_nom', 'field_prenom', 'field_date_de_naissance',
      'field_lieu_de_nai', 'field_nom_pere', 'field_profession_pere', 'field_nom_mere',
      'field_profession_mere', 'field_tuteur', 'field_adresse', 'field_phone', 'matricule',
      'field_classe', 'field_numero'];
    foreach ($field_carnet as $key => $field_value) {
      $status = TRUE;
      if (isset($inscription[$field_value]) && is_string($inscription[$field_value])) {
        $templateProcessor->setValue($field_value, $inscription[$field_value]);
        $status = FALSE;
      }
      if (isset($eleve[$field_value]) && is_string($eleve[$field_value])) {
        $templateProcessor->setValue($field_value, $eleve[$field_value]);
        $status = FALSE;
      }
      if ($field_value == 'field_classe') {
        $templateProcessor->setValue($field_value, $inscription[$field_value]['title']);
        $status = FALSE;
      }
      if ($field_value == 'matricule') {
        $templateProcessor->setValue('matricule', $eleve['title']);
        $status = FALSE;
      }
      if ($status) {
        $templateProcessor->setValue($field_value, '');
      }
    }
    $this->download($templateProcessor, $file_path, $eleve['title']);
  }

  /**
   * Returns the configured carnet template path (Drupal URI or filesystem path).
   */
  public function getConfiguredTemplatePath() {
    return trim((string) \Drupal::config('mz_henitsoa.settings')->get('carnet_template_path'));
  }

  /**
   * Saves the carnet template path in Drupal config.
   */
  public function setConfiguredTemplatePath($path) {
    \Drupal::configFactory()->getEditable('mz_henitsoa.settings')
      ->set('carnet_template_path', trim((string) $path))
      ->save();
  }

  /**
   * Resolves the default template URI from the media entity named carnet.docx.
   */
  public function getDefaultMediaTemplateUri() {
    $entity_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
      ->condition('name', 'carnet.docx')
      ->accessCheck(FALSE)
      ->execute();
    if (empty($entity_ids)) {
      return '';
    }
    $parser = \Drupal::service('entity_parser.manager');
    $template = $parser->media_parser(end($entity_ids));
    return (string) ($template['field_media_document']['uri'] ?? '');
  }

  /**
   * Returns config metadata for the Vue settings page.
   */
  public function getTemplateConfigInfo() {
    $configured_path = $this->getConfiguredTemplatePath();
    $default_uri = $this->getDefaultMediaTemplateUri();
    $active_path = $configured_path !== '' ? $configured_path : $default_uri;
    $resolved_path = $active_path !== '' ? $this->resolveTemplatePath($active_path) : FALSE;

    return [
      'template_path' => $configured_path,
      'default_path' => $default_uri,
      'active_path' => $active_path,
      'resolved_path' => $resolved_path ?: NULL,
      'exists' => (bool) $resolved_path,
      'uses_default' => $configured_path === '',
    ];
  }

  /**
   * Resolves a Drupal stream URI or filesystem path to a real .docx file path.
   */
  public function resolveTemplatePath($path) {
    $path = trim((string) $path);
    if ($path === '') {
      return FALSE;
    }

    if (strpos($path, '://') !== FALSE) {
      $real_path = \Drupal::service('file_system')->realpath($path);
      if ($real_path && is_file($real_path)) {
        return $real_path;
      }
    }

    if ($path[0] === '/') {
      return is_file($path) ? $path : FALSE;
    }

    $relative = DRUPAL_ROOT . '/' . ltrim($path, '/');
    if (is_file($relative)) {
      return $relative;
    }

    return FALSE;
  }

  /**
   * Returns the filesystem path of the carnet template to use.
   */
  public function getTemplateFilePath() {
    $configured_path = $this->getConfiguredTemplatePath();
    if ($configured_path !== '') {
      return $this->resolveTemplatePath($configured_path);
    }

    $default_uri = $this->getDefaultMediaTemplateUri();
    if ($default_uri !== '') {
      return $this->resolveTemplatePath($default_uri);
    }

    return FALSE;
  }

  public function download($templateProcessor, $file_path, $file_new_ouput) {
    $filename = basename($file_path, '.docx');
    $path = dirname($file_path);
    $file_new = $path . '/carnet-' . time() . '.docx';
    $templateProcessor->saveAs($file_new);
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=carnet_' . $file_new_ouput . '.docx');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_new));
    flush();
    readfile($file_new);
    unlink($file_new);
    exit;
  }

}
