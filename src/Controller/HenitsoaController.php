<?php

namespace Drupal\mz_henitsoa\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
/**
 * Class HenitsoaController.
 */
class HenitsoaController extends ControllerBase {
  /**
   * Carnet.
   * @return string
   * Return Hello string.
   */
  public function build() {   
    $id = \Drupal::request()->query->get('id'); 
    if($id){
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
      $service = \Drupal::service('carnet_henitsoa'); 
      $service->carnet($node);
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Implement method: carnet')
      ];     
    }else{
      return [
        '#type' => 'markup',
        '#markup' => $this->t('NO ID document')
      ];
    }

  }

  /**
   * Renders the mount point for the Vue.js henitsoaapp shell.
   *
   * @return array
   *   Empty render array; the actual UI is built client-side by the
   *   henitsoaapp theme (see mz_henitsoa_theme_suggestions and
   *   henitsoaapp/templates/layout/page.html.twig).
   */
  public function app() {
    return [
      '#markup' => '',
    ];
  }

  /**
   * JSON listing of "inscription" nodes for the henitsoaapp Vue front-end.
   *
   * Supports ?page=0&limit=25&search=texte (search matches the inscription
   * title, which mz_henitsoa_node_presave keeps in sync with the student's
   * full name).
   */
  public function listInscriptions(Request $request) {
    $page = max((int) $request->query->get('page', 0), 0);
    $limit = min(max((int) $request->query->get('limit', 25), 1), 100);
    $search = trim((string) $request->query->get('search', ''));

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->accessCheck(FALSE);
    if ($search !== '') {
      $query->condition('title', $search, 'CONTAINS');
    }

    $total = (clone $query)->count()->execute();
    $ids = $query->sort('created', 'DESC')->range($page * $limit, $limit)->execute();

    $items = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $classe = $node->get('field_classe')->entity;
      $eleve = $node->get('field_eleve')->entity;
      $items[] = [
        'id' => (int) $node->id(),
        'matricule' => $node->label(),
        'annee_scolaire' => $node->get('field_annee_scolaire')->value,
        'classe' => $classe ? $classe->label() : NULL,
        'eleve_nid' => (int) $node->get('field_eleve')->target_id,
        'photo_url' => $eleve ? $this->getStudentPhotoUrl($eleve) : NULL,
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'total' => (int) $total,
      'page' => $page,
      'limit' => $limit,
      'items' => $items,
    ]);
  }

  /**
   * JSON listing of "etudiant" nodes for the henitsoaapp Vue front-end.
   *
   * Includes all students; field_date_sortie is optional (a student may
   * still be inscribed while appearing in this list).
   * Supports ?page=0&limit=25&search=texte&adresse=texte.
   */
  public function listArchives(Request $request) {
    $page = max((int) $request->query->get('page', 0), 0);
    $limit = min(max((int) $request->query->get('limit', 25), 1), 100);
    $search = trim((string) $request->query->get('search', ''));
    $adresse = trim((string) $request->query->get('adresse', ''));

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'etudiant')
      ->accessCheck(FALSE);
    if ($search !== '') {
      $group = $query->orConditionGroup()
        ->condition('field_nom', $search, 'CONTAINS')
        ->condition('field_prenom', $search, 'CONTAINS');
      $query->condition($group);
    }
    if ($adresse !== '') {
      $query->condition('field_adresse', $adresse, 'CONTAINS');
    }

    $total = (clone $query)->count()->execute();
    $ids = $query->sort('nid', 'DESC')->range($page * $limit, $limit)->execute();

    $items = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $items[] = [
        'id' => (int) $node->id(),
        'nom' => $node->get('field_nom')->value,
        'prenom' => $node->get('field_prenom')->value,
        'date_entre' => $node->get('field_date_entre')->value,
        'date_sortie' => $node->get('field_date_sortie')->value,
        'adresse' => $node->get('field_adresse')->value,
        'photo_url' => $this->getStudentPhotoUrl($node),
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'total' => (int) $total,
      'page' => $page,
      'limit' => $limit,
      'items' => $items,
    ]);
  }

  /**
   * JSON listing of "classe" taxonomy terms, with the number of students
   * enrolled in the current school year, for the henitsoaapp Vue front-end.
   *
   * Supports ?search=texte (matches the class name).
   */
  public function listClasses(Request $request) {
    $search = trim((string) $request->query->get('search', ''));

    $query = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'classe')
      ->accessCheck(FALSE)
      ->sort('name', 'ASC');
    if ($search !== '') {
      $query->condition('name', $search, 'CONTAINS');
    }
    $ids = $query->execute();

    $annee_scolaire = $this->getCurrentSchoolYear();

    $items = [];
    foreach (Term::loadMultiple(array_values($ids)) as $term) {
      $effectif = \Drupal::entityQuery('node')
        ->condition('type', 'inscription')
        ->condition('field_classe', $term->id())
        ->condition('field_annee_scolaire', $annee_scolaire)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $items[] = [
        'id' => (int) $term->id(),
        'nom' => $term->label(),
        'effectif' => (int) $effectif,
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'annee_scolaire' => $annee_scolaire,
      'annees_scolaires' => $this->getSchoolYearOptions(),
      'total' => count($items),
      'items' => $items,
    ]);
  }

  /**
   * Creates a new "classe" taxonomy term.
   *
   * Expects a JSON body: {"nom": "..."}.
   */
  public function createClasse(Request $request) {
    return $this->createTerm('classe', $request);
  }

  /**
   * Creates a new "matiere" taxonomy term.
   *
   * Expects a JSON body: {"nom": "..."}.
   */
  public function createMatiere(Request $request) {
    return $this->createTerm('matiere', $request);
  }

  /**
   * Shared create logic for the "classe" and "matiere" vocabularies.
   */
  protected function createTerm($vid, Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $nom = trim((string) ($data['nom'] ?? ''));

    if ($nom === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Le nom est requis.'], 422);
    }

    $term = Term::create([
      'vid' => $vid,
      'name' => $nom,
    ]);
    $term->save();

    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $term->id(),
        'nom' => $term->label(),
      ],
    ], 201);
  }

  /**
   * Search endpoint for active (non-archived) "etudiant" nodes, used by the
   * inscription creation form's student picker.
   *
   * Supports ?search=texte&limit=10 (search matches nom/prenom).
   */
  public function searchEtudiants(Request $request) {
    $search = trim((string) $request->query->get('search', ''));
    $limit = min(max((int) $request->query->get('limit', 10), 1), 50);

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'etudiant')
      ->condition('field_date_sortie', NULL, 'IS NULL')
      ->accessCheck(FALSE)
      ->sort('field_nom', 'ASC')
      ->range(0, $limit);
    if ($search !== '') {
      $group = $query->orConditionGroup()
        ->condition('field_nom', $search, 'CONTAINS')
        ->condition('field_prenom', $search, 'CONTAINS');
      $query->condition($group);
    }
    $ids = $query->execute();

    $items = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $items[] = [
        'id' => (int) $node->id(),
        'nom' => $node->get('field_nom')->value,
        'prenom' => $node->get('field_prenom')->value,
        'matricule' => $node->label(),
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'items' => $items,
    ]);
  }

  /**
   * Creates a new "etudiant" node.
   *
   * Expects a JSON body: {"nom": "...", "prenom": "...", "genre": "1",
   * "date_entre": "YYYY-MM-DD", "date_sortie": "YYYY-MM-DD"}.
   * Genre: "1" = Garçon (matricule suffix G), "0" = Fille (suffix F).
   * When date_sortie is set the student is marked as having left the school.
   */
  public function createEtudiant(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $nom = trim((string) ($data['nom'] ?? ''));
    $prenom = trim((string) ($data['prenom'] ?? ''));
    $genre = (string) ($data['genre'] ?? '1');
    $date_entre = trim((string) ($data['date_entre'] ?? date('Y-m-d')));
    $date_sortie = trim((string) ($data['date_sortie'] ?? ''));

    if ($nom === '' || $prenom === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Le nom et le prénom sont requis.'], 422);
    }
    if (!in_array($genre, ['0', '1'], TRUE)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Genre invalide.'], 422);
    }

    $matricule = $this->generateMatricule($genre);
    $values = [
      'type' => 'etudiant',
      'title' => $matricule,
      'field_nom' => $nom,
      'field_prenom' => $prenom,
      'field_genre' => $genre,
      'field_date_entre' => $date_entre,
      'status' => 1,
    ];
    if ($date_sortie !== '') {
      $values['field_date_sortie'] = $date_sortie;
    }
    $node = Node::create($values);
    $node->save();

    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $node->id(),
        'nom' => $nom,
        'prenom' => $prenom,
        'matricule' => $matricule,
        'date_entre' => $node->get('field_date_entre')->value,
        'date_sortie' => $node->get('field_date_sortie')->value,
      ],
    ], 201);
  }

  /**
   * Generates the next matricule (e.g. "4709G") from existing etudiant titles.
   */
  protected function generateMatricule($genre) {
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'etudiant')
      ->accessCheck(FALSE)
      ->execute();
    $max = 0;
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      if (preg_match('/^(\d+)/', $node->label(), $matches)) {
        $max = max($max, (int) $matches[1]);
      }
    }
    $suffix = $genre === '1' ? 'G' : 'F';
    return ($max + 1) . $suffix;
  }

  /**
   * Creates a new "inscription" node.
   *
   * Expects a JSON body: {"eleve_nid": N, "classe_tid": N,
   * "annee_scolaire": "2025 -2026"}. Rejects duplicates (same student +
   * school year), mirroring _unique_inscript_validate() in
   * mz_henitsoa.module.
   */
  public function createInscription(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $eleve_nid = (int) ($data['eleve_nid'] ?? 0);
    $classe_tid = (int) ($data['classe_tid'] ?? 0);
    $annee_scolaire = trim((string) ($data['annee_scolaire'] ?? ''));

    if (!$eleve_nid || !$classe_tid || $annee_scolaire === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Élève, classe et année scolaire sont requis.'], 422);
    }

    $eleve = Node::load($eleve_nid);
    if (!$eleve || $eleve->bundle() !== 'etudiant') {
      return new JsonResponse(['status' => 'error', 'message' => "Élève introuvable."], 422);
    }
    $classe = Term::load($classe_tid);
    if (!$classe || $classe->bundle() !== 'classe') {
      return new JsonResponse(['status' => 'error', 'message' => "Classe introuvable."], 422);
    }

    $duplicate = \Drupal::service('mz_henitsoa.default')->checkifInscrExist($eleve_nid, $annee_scolaire);
    if ($duplicate) {
      return new JsonResponse([
        'status' => 'error',
        'message' => "Cet élève a déjà une inscription pour l'année $annee_scolaire.",
      ], 409);
    }

    $node = Node::create([
      'type' => 'inscription',
      'field_eleve' => ['target_id' => $eleve_nid],
      'field_classe' => ['target_id' => $classe_tid],
      'field_annee_scolaire' => $annee_scolaire,
      'status' => 1,
    ]);
    $node->save();

    $node = Node::load($node->id());
    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $node->id(),
        'matricule' => $node->label(),
        'annee_scolaire' => $node->get('field_annee_scolaire')->value,
        'classe' => $classe->label(),
        'eleve_nid' => $eleve_nid,
        'photo_url' => $this->getStudentPhotoUrl($eleve),
      ],
    ], 201);
  }

  /**
   * Returns field_annee_scolaire's configured allowed values, most recent
   * first (e.g. ["2028 -2029", ..., "2021 -2022"]).
   *
   * @return string[]
   */
  protected function getSchoolYearOptions() {
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'inscription')['field_annee_scolaire'];
    $years = array_keys($field->getSettings()['allowed_values']);
    rsort($years);
    return $years;
  }

  /**
   * JSON listing of "matiere" taxonomy terms for the henitsoaapp Vue
   * front-end.
   *
   * Supports ?search=texte (matches the subject name).
   */
  public function listMatieres(Request $request) {
    $search = trim((string) $request->query->get('search', ''));

    $query = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'matiere')
      ->accessCheck(FALSE)
      ->sort('name', 'ASC');
    if ($search !== '') {
      $query->condition('name', $search, 'CONTAINS');
    }
    $ids = $query->execute();

    $items = [];
    foreach (Term::loadMultiple(array_values($ids)) as $term) {
      $items[] = [
        'id' => (int) $term->id(),
        'nom' => $term->label(),
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'total' => count($items),
      'items' => $items,
    ]);
  }

  /**
   * Finds the most recent school year that actually has inscriptions,
   * among field_annee_scolaire's configured allowed values.
   *
   * @return string|null
   *   E.g. "2025 -2026", or NULL if no inscription exists at all.
   */
  protected function getCurrentSchoolYear() {
    foreach ($this->getSchoolYearOptions() as $year) {
      $count = \Drupal::entityQuery('node')
        ->condition('type', 'inscription')
        ->condition('field_annee_scolaire', $year)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      if ($count > 0) {
        return $year;
      }
    }
    return NULL;
  }

  /**
   * JSON listing of "ecolage" (tuition payment) nodes for the henitsoaapp
   * Vue front-end.
   *
   * Supports ?page=0&limit=25&search=texte (search matches the student's
   * name via the linked inscription).
   */
  public function listEcolages(Request $request) {
    $page = max((int) $request->query->get('page', 0), 0);
    $limit = min(max((int) $request->query->get('limit', 25), 1), 100);
    $search = trim((string) $request->query->get('search', ''));

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'ecolage')
      ->accessCheck(FALSE);

    if ($search !== '') {
      $inscription_ids = \Drupal::entityQuery('node')
        ->condition('type', 'inscription')
        ->condition('title', $search, 'CONTAINS')
        ->accessCheck(FALSE)
        ->execute();
      if (empty($inscription_ids)) {
        return new JsonResponse([
          'status' => 'success',
          'total' => 0,
          'page' => $page,
          'limit' => $limit,
          'items' => [],
        ]);
      }
      $query->condition('field_inscrit', array_values($inscription_ids), 'IN');
    }

    $total = (clone $query)->count()->execute();
    $ids = $query->sort('created', 'DESC')->range($page * $limit, $limit)->execute();

    $statuses = $this->getEcolageStatusOptions();

    $items = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $inscrit = $node->get('field_inscrit')->entity;
      $mois = $node->get('field_mois')->entity;
      $status_value = $node->get('field_status')->value;
      $items[] = [
        'id' => (int) $node->id(),
        'eleve' => $inscrit ? $inscrit->label() : NULL,
        'mois' => $mois ? $mois->label() : NULL,
        'montant' => (int) $node->get('field_montant')->value,
        'status' => $status_value,
        'status_label' => $statuses[$status_value] ?? $status_value,
        'annee_scolaire' => $node->get('field_annee_scolaire')->value,
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'total' => (int) $total,
      'page' => $page,
      'limit' => $limit,
      'items' => $items,
    ]);
  }

  /**
   * Returns the reference data needed by the "Gestion des ecolages" form:
   * the 12 "mois" taxonomy terms and field_status's allowed values.
   */
  public function getEcolageFormOptions() {
    $mois = $this->getMoisTerms();

    $statuses = [];
    foreach ($this->getEcolageStatusOptions() as $value => $label) {
      $statuses[] = ['value' => $value, 'label' => $label];
    }

    return new JsonResponse([
      'status' => 'success',
      'mois' => $mois,
      'statuses' => $statuses,
    ]);
  }

  /**
   * Creates a new "ecolage" (tuition payment) node.
   *
   * Expects a JSON body: {"inscrit_nid": N, "mois_tid": N,
   * "annee_scolaire": "2025 -2026", "montant": 30000, "status": "1"}.
   * Rejects duplicates (same inscription + month), mirroring
   * _unique_title_validate() in mz_henitsoa.module. Saving the node
   * automatically updates the inscription's field_ecolage_status via the
   * existing mz_henitsoa_node_insert() hook.
   */
  public function createEcolage(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $inscrit_nid = (int) ($data['inscrit_nid'] ?? 0);
    $mois_tid = (int) ($data['mois_tid'] ?? 0);
    $annee_scolaire = trim((string) ($data['annee_scolaire'] ?? ''));
    $montant = (int) ($data['montant'] ?? 0);
    $status = (string) ($data['status'] ?? '1');

    if (!$inscrit_nid || !$mois_tid || $annee_scolaire === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Inscription, mois et année scolaire sont requis.'], 422);
    }

    $inscrit = Node::load($inscrit_nid);
    if (!$inscrit || $inscrit->bundle() !== 'inscription') {
      return new JsonResponse(['status' => 'error', 'message' => "Inscription introuvable."], 422);
    }
    $mois = Term::load($mois_tid);
    if (!$mois || $mois->bundle() !== 'mois') {
      return new JsonResponse(['status' => 'error', 'message' => "Mois introuvable."], 422);
    }

    $duplicate = \Drupal::service('mz_henitsoa.default')->checkifEcolageExist($inscrit_nid, $mois_tid);
    if ($duplicate) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Un écolage pour ce mois existe déjà pour cette inscription.',
      ], 409);
    }

    $node = Node::create([
      'type' => 'ecolage',
      'title' => date('Ymd') . '_' . uniqid(),
      'field_inscrit' => ['target_id' => $inscrit_nid],
      'field_mois' => ['target_id' => $mois_tid],
      'field_annee_scolaire' => $annee_scolaire,
      'field_montant' => $montant,
      'field_status' => $status,
      'status' => 1,
    ]);
    $node->save();

    $statuses = $this->getEcolageStatusOptions();

    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $node->id(),
        'eleve' => $inscrit->label(),
        'mois' => $mois->label(),
        'montant' => $montant,
        'status' => $status,
        'status_label' => $statuses[$status] ?? $status,
        'annee_scolaire' => $annee_scolaire,
      ],
    ], 201);
  }

  /**
   * Returns field_status's configured allowed values, keyed by value
   * (e.g. ["1" => "Payé", "0" => "Non payé", ...]).
   *
   * @return string[]
   */
  protected function getEcolageStatusOptions() {
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'ecolage')['field_status'];
    return $field->getSettings()['allowed_values'];
  }

  /**
   * Returns the 12 "mois" taxonomy terms, ordered by the Malagasy school
   * year (September to August) since they have no configured weight.
   *
   * @return array
   *   Array of ['id' => int, 'nom' => string].
   */
  protected function getMoisTerms() {
    $mois_ids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'mois')
      ->accessCheck(FALSE)
      ->execute();

    $school_year_order = [
      'SEPTEMBRE', 'OCTOBRE', 'NOVEMBRE', 'DECEMBRE', 'JANVIER', 'FEVRIER',
      'MARS', 'AVRIL', 'MAI', 'JUIN', 'JUILLET', 'AOUT',
    ];
    $terms = Term::loadMultiple(array_values($mois_ids));
    usort($terms, function ($a, $b) use ($school_year_order) {
      $pos_a = array_search($a->label(), $school_year_order);
      $pos_b = array_search($b->label(), $school_year_order);
      return ($pos_a === FALSE ? 99 : $pos_a) <=> ($pos_b === FALSE ? 99 : $pos_b);
    });

    $mois = [];
    foreach ($terms as $term) {
      $mois[] = ['id' => (int) $term->id(), 'nom' => $term->label()];
    }
    return $mois;
  }

  /**
   * Reads a field's configured allowed_values, keyed by value.
   *
   * @return string[]
   */
  protected function getAllowedValues($bundle, $field_name) {
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', $bundle)[$field_name];
    return $field->getSettings()['allowed_values'];
  }

  /**
   * Formats an "etudiant" node into the detail shape shared by the
   * inscription detail (nested student) and the archive detail endpoints.
   */
  protected function formatEtudiant($node) {
    $genres = $this->getAllowedValues('etudiant', 'field_genre');
    $genre_value = $node->get('field_genre')->value;

    return [
      'id' => (int) $node->id(),
      'matricule' => $node->label(),
      'nom' => $node->get('field_nom')->value,
      'prenom' => $node->get('field_prenom')->value,
      'genre' => $genre_value,
      'genre_label' => $genres[$genre_value] ?? NULL,
      'date_de_naissance' => $node->get('field_date_de_naissance')->value,
      'lieu_de_naissance' => $node->get('field_lieu_de_nai')->value,
      'date_entre' => $node->get('field_date_entre')->value,
      'date_sortie' => $node->get('field_date_sortie')->value,
      'nom_pere' => $node->get('field_nom_pere')->value,
      'profession_pere' => $node->get('field_profession_pere')->value,
      'nom_mere' => $node->get('field_nom_mere')->value,
      'profession_mere' => $node->get('field_profession_mere')->value,
      'tuteur' => $node->get('field_tuteur')->value,
      'adresse' => $node->get('field_adresse')->value,
      'phone' => $node->get('field_phone')->value,
      'photo_url' => $this->getStudentPhotoUrl($node),
    ];
  }

  /**
   * Full detail of a single "inscription" node, including the linked
   * student and a month-by-month tuition payment checklist (derived from
   * field_ecolage_status, kept in sync by the existing checkEcolage() /
   * unCheckEcolage() hooks).
   */
  public function getInscriptionDetail($id) {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'inscription') {
      return new JsonResponse(['status' => 'error', 'message' => 'Inscription introuvable.'], 404);
    }

    $classe = $node->get('field_classe')->entity;
    $eleve = $node->get('field_eleve')->entity;
    $droits = $this->getAllowedValues('inscription', 'field_droite');
    $droits_value = $node->get('field_droite')->value;

    $paid_month_ids = array_map(
      function ($item) { return (int) $item['target_id']; },
      $node->get('field_ecolage_status')->getValue()
    );
    $ecolage_months = array_map(function ($mois) use ($paid_month_ids) {
      return $mois + ['paid' => in_array($mois['id'], $paid_month_ids, TRUE)];
    }, $this->getMoisTerms());

    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $node->id(),
        'matricule' => $node->label(),
        'annee_scolaire' => $node->get('field_annee_scolaire')->value,
        'classe' => $classe ? $classe->label() : NULL,
        'classe_tid' => $classe ? (int) $classe->id() : NULL,
        'description' => $node->get('field_description')->value,
        'droits_status' => $droits_value,
        'droits_status_label' => $droits[$droits_value] ?? NULL,
        'eleve' => $eleve ? $this->formatEtudiant($eleve) : NULL,
        'ecolage_months' => $ecolage_months,
      ],
    ]);
  }

  /**
   * Full detail of a single archived "etudiant" node.
   */
  public function getArchiveDetail($id) {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'etudiant') {
      return new JsonResponse(['status' => 'error', 'message' => 'Élève introuvable.'], 404);
    }
    return new JsonResponse([
      'status' => 'success',
      'item' => $this->formatEtudiant($node),
    ]);
  }

  /**
   * Updates an archived "etudiant" node.
   *
   * Expects a JSON body with any of the student fields. An optional "photo"
   * key may contain a base64 data-URL (data:image/...;base64,...).
   */
  public function updateArchive($id, Request $request) {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'etudiant') {
      return new JsonResponse(['status' => 'error', 'message' => 'Élève introuvable.'], 404);
    }

    $data = json_decode($request->getContent(), TRUE) ?: [];
    $string_fields = [
      'nom' => 'field_nom',
      'prenom' => 'field_prenom',
      'lieu_de_naissance' => 'field_lieu_de_nai',
      'nom_pere' => 'field_nom_pere',
      'profession_pere' => 'field_profession_pere',
      'nom_mere' => 'field_nom_mere',
      'profession_mere' => 'field_profession_mere',
      'tuteur' => 'field_tuteur',
      'adresse' => 'field_adresse',
      'phone' => 'field_phone',
    ];
    $date_fields = [
      'date_de_naissance' => 'field_date_de_naissance',
      'date_entre' => 'field_date_entre',
      'date_sortie' => 'field_date_sortie',
    ];

    if (array_key_exists('nom', $data) && trim((string) $data['nom']) === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Le nom est requis.'], 422);
    }
    if (array_key_exists('prenom', $data) && trim((string) $data['prenom']) === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Le prénom est requis.'], 422);
    }
    if (array_key_exists('genre', $data) && !in_array((string) $data['genre'], ['0', '1'], TRUE)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Genre invalide.'], 422);
    }

    foreach ($string_fields as $key => $field_name) {
      if (array_key_exists($key, $data)) {
        $node->set($field_name, trim((string) $data[$key]));
      }
    }
    foreach ($date_fields as $key => $field_name) {
      if (array_key_exists($key, $data)) {
        $value = trim((string) $data[$key]);
        $node->set($field_name, $value === '' ? NULL : $value);
      }
    }
    if (array_key_exists('genre', $data)) {
      $node->set('field_genre', (string) $data['genre']);
    }

    if (!empty($data['photo'])) {
      try {
        $this->attachStudentPhoto($node, (string) $data['photo']);
      }
      catch (\InvalidArgumentException $e) {
        return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
      }
    }

    $node->save();
    $node = Node::load($node->id());

    return new JsonResponse([
      'status' => 'success',
      'item' => $this->formatEtudiant($node),
    ]);
  }

  /**
   * Uploads a student photo via multipart/form-data.
   */
  public function uploadArchivePhoto($id, Request $request) {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'etudiant') {
      return new JsonResponse(['status' => 'error', 'message' => 'Élève introuvable.'], 404);
    }

    $upload = $request->files->get('photo');
    if (!$upload) {
      return new JsonResponse(['status' => 'error', 'message' => 'Aucune photo reçue.'], 422);
    }

    try {
      $mime = strtolower((string) $upload->getClientMimeType());
      $extensions = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
      ];
      if (!isset($extensions[$mime])) {
        throw new \InvalidArgumentException('Format de photo non supporté.');
      }

      $file_info = [
        'tmp_name' => $upload->getPathname(),
        'error' => $upload->getError(),
        'name' => 'student_' . $node->id() . '_' . time() . '.' . $extensions[$mime],
      ];
      $file = $this->saveFileDirect($file_info, 'public://student-photos/');
      if (!$file) {
        return new JsonResponse(['status' => 'error', 'message' => 'Photo invalide.'], 422);
      }
      $file->setPermanent();
      $file->save();
      $this->attachStudentPhotoFile($node, $file);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 422);
    }

    $node->save();
    $node = Node::load($node->id());

    return new JsonResponse([
      'status' => 'success',
      'item' => $this->formatEtudiant($node),
    ]);
  }

  /**
   * Full detail of a single "classe" taxonomy term: its info plus the list
   * of students currently enrolled in it for the current school year.
   */
  public function getClasseDetail($id) {
    $term = Term::load($id);
    if (!$term || $term->bundle() !== 'classe') {
      return new JsonResponse(['status' => 'error', 'message' => 'Classe introuvable.'], 404);
    }

    $annee_scolaire = $this->getCurrentSchoolYear();
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_classe', $id)
      ->condition('field_annee_scolaire', $annee_scolaire)
      ->accessCheck(FALSE)
      ->sort('title', 'ASC')
      ->execute();

    $students = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $eleve = $node->get('field_eleve')->entity;
      $students[] = [
        'id' => (int) $node->id(),
        'matricule' => $node->label(),
        'eleve_nid' => (int) $node->get('field_eleve')->target_id,
        'photo_url' => $eleve ? $this->getStudentPhotoUrl($eleve) : NULL,
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $term->id(),
        'nom' => $term->label(),
        'annee_scolaire' => $annee_scolaire,
        'effectif' => count($students),
      ],
      'students' => $students,
    ]);
  }

  /**
   * Full detail of a single "matiere" taxonomy term.
   */
  public function getMatiereDetail($id) {
    $term = Term::load($id);
    if (!$term || $term->bundle() !== 'matiere') {
      return new JsonResponse(['status' => 'error', 'message' => 'Matière introuvable.'], 404);
    }
    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $term->id(),
        'nom' => $term->label(),
      ],
    ]);
  }

  /**
   * Full detail of a single "ecolage" (tuition payment) node.
   */
  public function getEcolageDetail($id) {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'ecolage') {
      return new JsonResponse(['status' => 'error', 'message' => 'Écolage introuvable.'], 404);
    }

    $inscrit = $node->get('field_inscrit')->entity;
    $mois = $node->get('field_mois')->entity;
    $statuses = $this->getEcolageStatusOptions();
    $status_value = $node->get('field_status')->value;

    return new JsonResponse([
      'status' => 'success',
      'item' => [
        'id' => (int) $node->id(),
        'eleve' => $inscrit ? $inscrit->label() : NULL,
        'inscrit_nid' => $inscrit ? (int) $inscrit->id() : NULL,
        'mois' => $mois ? $mois->label() : NULL,
        'montant' => (int) $node->get('field_montant')->value,
        'status' => $status_value,
        'status_label' => $statuses[$status_value] ?? $status_value,
        'annee_scolaire' => $node->get('field_annee_scolaire')->value,
        'description' => $node->get('field_description')->value,
      ],
    ]);
  }

  /**
   * Resolves the "thumbnail" style URL of an etudiant image field.
   *
   * @param \Drupal\node\NodeInterface $eleve
   *   The etudiant node.
   *
   * @return string|null
   *   The thumbnail URL, or NULL if no photo is set.
   */
  protected function getStudentPhotoUrl($eleve) {
    $photo_field = $this->getStudentPhotoFieldName($eleve);
    if (!$photo_field) {
      return NULL;
    }
    $photo_entity = $eleve->get($photo_field)->entity;
    if (!$photo_entity) {
      return NULL;
    }
    // New model: field_photo_new is an image/file field on etudiant.
    // Backward compatibility: if historical media references still exist.
    $file = NULL;
    if ($photo_entity instanceof File) {
      $file = $photo_entity;
    }
    elseif ($photo_entity instanceof Media && $photo_entity->hasField('field_media_image')) {
      $file = $photo_entity->get('field_media_image')->entity;
    }
    if (!$file) {
      return NULL;
    }
    $style = \Drupal::entityTypeManager()->getStorage('image_style')->load('thumbnail');
    if (!$style) {
      return NULL;
    }
    $absolute_url = $style->buildUrl($file->getFileUri());
    return \Drupal::service('file_url_generator')->transformRelative($absolute_url);
  }

  /**
   * Creates a media image from a base64 data-URL and attaches it.
   */
  protected function attachStudentPhoto(Node $node, $photo) {
    if (!preg_match('/^data:(image\/[\w.+-]+);base64,(.+)$/', $photo, $matches)) {
      throw new \InvalidArgumentException('Format de photo invalide.');
    }
    $data = base64_decode($matches[2], TRUE);
    if ($data === FALSE) {
      throw new \InvalidArgumentException('Photo invalide.');
    }
    $this->attachStudentPhotoBinary($node, $data, $matches[1]);
  }

  /**
   * Creates a media image from binary data and attaches it.
   */
  protected function attachStudentPhotoBinary(Node $node, $data, $mime) {
    $extensions = [
      'image/jpeg' => 'jpg',
      'image/jpg' => 'jpg',
      'image/pjpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
    ];
    $mime = strtolower((string) $mime);
    if (!isset($extensions[$mime])) {
      throw new \InvalidArgumentException('Format de photo non supporté.');
    }
    $ext = $extensions[$mime];
    $directory = 'public://student-photos';
    \Drupal::service('file_system')->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    $filename = 'student_' . $node->id() . '_' . time() . '.' . $ext;
    $uri = $directory . '/' . $filename;

    $file = \Drupal::service('file.repository')->writeData(
      $data,
      $uri,
      FileSystemInterface::EXISTS_RENAME
    );
    $file->setPermanent();
    $file->save();

    $this->attachStudentPhotoFile($node, $file);
  }

  /**
   * Creates a media image from an existing file entity and attaches it.
   */
  protected function attachStudentPhotoFile(Node $node, File $file) {
    $photo_field = $this->getStudentPhotoFieldName($node);
    if (!$photo_field) {
      throw new \InvalidArgumentException('Champ photo introuvable.');
    }
    $field_definition = $node->getFieldDefinition($photo_field);
    $target_type = (string) ($field_definition->getSetting('target_type') ?? '');
    $alt = trim($node->get('field_nom')->value . ' ' . $node->get('field_prenom')->value);

    if ($target_type === 'file') {
      $node->set($photo_field, [
        'target_id' => $file->id(),
        'alt' => $alt,
      ]);
      return;
    }

    // Backward compatibility for installations where photo field is media ref.
    $filename = $file->getFilename();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $filename,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
      ],
      'status' => 1,
      'uid' => \Drupal::currentUser()->id(),
    ]);
    $media->save();
    $node->set($photo_field, ['target_id' => $media->id()]);
  }

  /**
   * Returns the etudiant photo field name, preferring field_photo_new.
   */
  private function getStudentPhotoFieldName($entity): ?string {
    if ($entity->hasField('field_photo_new')) {
      return 'field_photo_new';
    }
    if ($entity->hasField('field_photo')) {
      return 'field_photo';
    }
    return NULL;
  }

  /**
   * Saves an uploaded file info array directly to a Drupal stream wrapper dir.
   */
  private function saveFileDirect(array $file_info, string $dir): ?File {
    if (!isset($file_info['tmp_name']) || ((int) ($file_info['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_OK) {
      return NULL;
    }
    $data = file_get_contents($file_info['tmp_name']);
    if ($data === FALSE || $data === '') {
      return NULL;
    }

    $this->fileSystem()->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = rtrim($dir, '/') . '/' . basename((string) $file_info['name']);

    $file = file_save_data($data, $uri, FileSystemInterface::EXISTS_RENAME);
    return $file ?: NULL;
  }

  /**
   * Shortcut to the file system service.
   */
  private function fileSystem(): FileSystemInterface {
    return \Drupal::service('file_system');
  }

  /**
   * Returns the carnet.docx template path configuration.
   */
  public function getCarnetConfig() {
    $service = \Drupal::service('carnet_henitsoa');
    return new JsonResponse([
      'status' => 'success',
      'item' => $service->getTemplateConfigInfo(),
    ]);
  }

  /**
   * Updates the carnet.docx template path configuration.
   *
   * Expects JSON: {"template_path": "public://2022-10/carnet.docx"}.
   * An empty string resets to the default media entity.
   */
  public function updateCarnetConfig(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    if (!array_key_exists('template_path', $data)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Le chemin du template est requis.'], 422);
    }

    $template_path = trim((string) $data['template_path']);
    $service = \Drupal::service('carnet_henitsoa');

    if ($template_path !== '' && !$service->resolveTemplatePath($template_path)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Fichier introuvable. Utilisez un chemin Drupal (public://...) ou un chemin absolu vers un fichier .docx.',
      ], 422);
    }

    $service->setConfiguredTemplatePath($template_path);

    return new JsonResponse([
      'status' => 'success',
      'item' => $service->getTemplateConfigInfo(),
    ]);
  }

}
