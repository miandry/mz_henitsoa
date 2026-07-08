<?php

namespace Drupal\mz_henitsoa;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gestion des activités scolaires, inscriptions et paiements liés.
 */
class ActiviteService {

  /**
   * Liste des activités avec résumé de collecte.
   */
  public function listActivites(Request $request): array {
    if (!$this->bundleExists('activite')) {
      return ['status' => 'success', 'total' => 0, 'items' => []];
    }

    $search = trim((string) $request->query->get('search', ''));
    $statut = trim((string) $request->query->get('statut', ''));
    $annee_scolaire = trim((string) $request->query->get('annee_scolaire', ''));
    if ($annee_scolaire === '') {
      $annee_scolaire = $this->getActiveSchoolYear() ?? '';
    }

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'activite')
      ->accessCheck(FALSE)
      ->sort('created', 'DESC');

    if ($search !== '') {
      $query->condition('title', $search, 'CONTAINS');
    }
    if ($statut !== '') {
      $query->condition('field_statut_activite', $statut);
    }
    if ($annee_scolaire !== '' && $this->fieldExists('activite', 'field_annee_scolaire')) {
      $query->condition('field_annee_scolaire', $annee_scolaire);
    }

    $ids = $query->execute();
    $items = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $items[] = $this->formatActiviteSummary($node);
    }

    return [
      'status' => 'success',
      'annee_scolaire' => $annee_scolaire !== '' ? $annee_scolaire : NULL,
      'annees_scolaires' => $this->getSchoolYearOptions(),
      'total' => count($items),
      'items' => $items,
    ];
  }

  /**
   * Options pour les formulaires Vue.
   */
  public function getFormOptions(): array {
    return [
      'status' => 'success',
      'types_activite' => $this->getListOptions('activite', 'field_type_activite') ?: $this->defaultTypeActiviteOptions(),
      'statuts_activite' => $this->getListOptions('activite', 'field_statut_activite') ?: $this->defaultStatutActiviteOptions(),
      'statuts_participation' => $this->getListOptions('participation_activite', 'field_statut_participation') ?: $this->defaultStatutParticipationOptions(),
      'classes' => $this->getClassesList(),
      'annee_scolaire' => $this->getActiveSchoolYear(),
      'annees_scolaires' => $this->getSchoolYearOptions(),
    ];
  }

  /**
   * Crée une activité.
   */
  public function createActivite(array $data): array {
    if (!$this->bundleExists('activite')) {
      return ['status' => 'error', 'message' => 'Le type de contenu activite est introuvable.'];
    }

    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
      return ['status' => 'error', 'message' => 'Le titre est requis.'];
    }

    $values = [
      'type' => 'activite',
      'title' => $title,
      'status' => 1,
    ];

    $this->setFieldValue($values, 'activite', 'field_type_activite', $data['type_activite'] ?? 'autre');
    $this->setFieldValue($values, 'activite', 'field_date_activite', $data['date_activite'] ?? NULL);
    $this->setFieldValue($values, 'activite', 'field_date_limite_inscription', $data['date_limite_inscription'] ?? NULL);
    $this->setFieldValue($values, 'activite', 'field_montant_participation', (int) ($data['montant_participation'] ?? 0));
    $this->setFieldValue($values, 'activite', 'field_objectif_collecte', (int) ($data['objectif_collecte'] ?? 0));
    $this->setFieldValue($values, 'activite', 'field_participation_obligatoire', !empty($data['participation_obligatoire']) ? 1 : 0);
    $this->setFieldValue($values, 'activite', 'field_statut_activite', $data['statut_activite'] ?? 'planifiee');
    $this->setFieldValue($values, 'activite', 'field_description', trim((string) ($data['description'] ?? '')));
    $annee_scolaire = trim((string) ($data['annee_scolaire'] ?? '')) ?: $this->getActiveSchoolYear();
    $this->setFieldValue($values, 'activite', 'field_annee_scolaire', $annee_scolaire);

    $classes = array_filter(array_map('intval', (array) ($data['classes_cibles'] ?? [])));
    if ($this->fieldExists('activite', 'field_classes_cibles') && !empty($classes)) {
      $values['field_classes_cibles'] = array_map(fn($id) => ['target_id' => $id], $classes);
    }

    $node = Node::create($values);
    $node->save();

    return [
      'status' => 'success',
      'item' => $this->formatActiviteSummary(Node::load($node->id())),
    ];
  }

  /**
   * Met à jour une activité existante.
   */
  public function updateActivite(int $activite_id, array $data): array {
    $activite = Node::load($activite_id);
    if (!$activite || $activite->bundle() !== 'activite') {
      return ['status' => 'error', 'message' => 'Activité introuvable.'];
    }

    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
      return ['status' => 'error', 'message' => 'Le titre est requis.'];
    }

    $activite->setTitle($title);

    if ($activite->hasField('field_type_activite')) {
      $activite->set('field_type_activite', $data['type_activite'] ?? 'autre');
    }
    if ($activite->hasField('field_date_activite')) {
      $activite->set('field_date_activite', $data['date_activite'] ?: NULL);
    }
    if ($activite->hasField('field_date_limite_inscription')) {
      $activite->set('field_date_limite_inscription', $data['date_limite_inscription'] ?: NULL);
    }
    if ($activite->hasField('field_montant_participation')) {
      $activite->set('field_montant_participation', (int) ($data['montant_participation'] ?? 0));
    }
    if ($activite->hasField('field_objectif_collecte')) {
      $activite->set('field_objectif_collecte', (int) ($data['objectif_collecte'] ?? 0));
    }
    if ($activite->hasField('field_participation_obligatoire')) {
      $activite->set('field_participation_obligatoire', !empty($data['participation_obligatoire']) ? 1 : 0);
    }
    if ($activite->hasField('field_statut_activite')) {
      $activite->set('field_statut_activite', $data['statut_activite'] ?? 'planifiee');
    }
    if ($activite->hasField('field_description')) {
      $activite->set('field_description', trim((string) ($data['description'] ?? '')));
    }

    $classes = array_filter(array_map('intval', (array) ($data['classes_cibles'] ?? [])));
    if ($this->fieldExists('activite', 'field_classes_cibles')) {
      $activite->set(
        'field_classes_cibles',
        empty($classes) ? [] : array_map(fn($id) => ['target_id' => $id], $classes)
      );
    }

    $activite->save();

    return $this->getActiviteDetail($activite_id);
  }

  /**
   * Détail activité avec inscrits et paiements séparés.
   */
  public function getActiviteDetail(int $activite_id): array {
    $activite = Node::load($activite_id);
    if (!$activite || $activite->bundle() !== 'activite') {
      return ['status' => 'error', 'message' => 'Activité introuvable.'];
    }

    $montant_participation = $this->getIntField($activite, 'field_montant_participation');
    $annee_scolaire = $this->getActiviteAnneeScolaire($activite);
    $participations = $this->loadParticipations($activite_id, $annee_scolaire);
    $paiements = $this->loadPaiementsByEleve($activite_id);
    $rows = [];

    foreach ($participations as $participation) {
      $eleve_id = $participation['eleve_id'];
      $paiement = $paiements[$eleve_id] ?? NULL;
      $montant_paye = $paiement['montant_paye'] ?? 0;
      $est_confirme = $montant_participation > 0
        ? $montant_paye >= $montant_participation
        : ($montant_paye > 0);
      $inscription_statut = $est_confirme ? 'confirme' : $participation['statut_inscription'];
      $inscription_labels = $this->getListOptions('participation_activite', 'field_statut_participation')
        ?: $this->defaultStatutParticipationOptions();
      $rows[] = [
        'participation_id' => $participation['id'],
        'eleve_id' => $eleve_id,
        'eleve' => $participation['eleve'],
        'classe' => $participation['classe'],
        'statut_inscription' => $inscription_statut,
        'statut_inscription_label' => $inscription_labels[$inscription_statut] ?? $inscription_statut,
        'statut_paiement' => $this->resolvePaiementStatut($montant_paye, $montant_participation),
        'statut_paiement_label' => $this->resolvePaiementLabel($montant_paye, $montant_participation),
        'montant_du' => $montant_participation,
        'montant_paye' => $montant_paye,
        'solde' => max(0, $montant_participation - $montant_paye),
        'date_paiement' => $paiement['date_paiement'] ?? NULL,
        'numero_recu' => $paiement['numero_recu'] ?? NULL,
        'est_confirme' => $est_confirme,
        'url_eleve' => '/app/archives-eleves/' . $eleve_id,
      ];
    }

    $collecte = array_sum(array_column($rows, 'montant_paye'));
    $objectif = $this->getIntField($activite, 'field_objectif_collecte');
    if ($objectif <= 0) {
      $objectif = $montant_participation * max(count($rows), 1);
    }

    return [
      'status' => 'success',
      'item' => $this->formatActiviteDetail($activite, $collecte, $objectif, count($rows)),
      'participants' => $rows,
      'summary' => [
        'inscrits' => count($rows),
        'payes' => count(array_filter($rows, fn($r) => $r['statut_paiement'] === 'paye')),
        'non_payes' => count(array_filter($rows, fn($r) => $r['statut_paiement'] === 'non_paye')),
        'partiels' => count(array_filter($rows, fn($r) => $r['statut_paiement'] === 'partiel')),
        'collecte' => $collecte,
        'objectif' => $objectif,
        'taux' => $objectif > 0 ? (int) round(($collecte / $objectif) * 100) : 0,
      ],
    ];
  }

  /**
   * Inscrit un élève à une activité (sans paiement).
   */
  public function createParticipation(int $activite_id, array $data): array {
    if (!$this->bundleExists('participation_activite')) {
      return ['status' => 'error', 'message' => 'Le type participation_activite est introuvable.'];
    }

    $activite = Node::load($activite_id);
    if (!$activite || $activite->bundle() !== 'activite') {
      return ['status' => 'error', 'message' => 'Activité introuvable.'];
    }

    $eleve_id = (int) ($data['eleve_id'] ?? 0);
    $eleve = Node::load($eleve_id);
    if (!$eleve || $eleve->bundle() !== 'etudiant') {
      return ['status' => 'error', 'message' => 'Élève introuvable.'];
    }

    if ($this->participationExists($activite_id, $eleve_id)) {
      return ['status' => 'error', 'message' => 'Cet élève est déjà inscrit à cette activité.'];
    }

    $validation_error = $this->validateEleveForActivite($activite, $eleve_id);
    if ($validation_error !== NULL) {
      return ['status' => 'error', 'message' => $validation_error];
    }

    $values = [
      'type' => 'participation_activite',
      'title' => $activite->label() . ' — ' . $eleve->label(),
      'status' => 1,
    ];
    $this->setFieldValue($values, 'participation_activite', 'field_eleve', ['target_id' => $eleve_id]);
    $this->setFieldValue($values, 'participation_activite', 'field_activite', ['target_id' => $activite_id]);
    $this->setFieldValue($values, 'participation_activite', 'field_statut_participation', 'inscrit');

    $node = Node::create($values);
    $node->save();

    return $this->getActiviteDetail($activite_id);
  }

  /**
   * Enregistre un paiement lié à une activité.
   */
  public function createPaiement(int $activite_id, array $data): array {
    if (!$this->bundleExists('paiement')) {
      return ['status' => 'error', 'message' => 'Le type de contenu paiement est introuvable.'];
    }

    $activite = Node::load($activite_id);
    if (!$activite || $activite->bundle() !== 'activite') {
      return ['status' => 'error', 'message' => 'Activité introuvable.'];
    }

    $eleve_id = (int) ($data['eleve_id'] ?? 0);
    if (!$this->participationExists($activite_id, $eleve_id)) {
      return ['status' => 'error', 'message' => "L'élève doit d'abord être inscrit à l'activité."];
    }

    $montant = (int) ($data['montant_paye'] ?? 0);
    if ($montant <= 0) {
      return ['status' => 'error', 'message' => 'Le montant payé est requis.'];
    }

    $eleve = Node::load($eleve_id);
    $values = [
      'type' => 'paiement',
      'title' => 'Paiement ' . $activite->label() . ' — ' . $eleve->label(),
      'status' => 1,
    ];

    $montant_du = $this->getIntField($activite, 'field_montant_participation');
    $this->setFieldValue($values, 'paiement', 'field_eleve', ['target_id' => $eleve_id]);
    $this->setFieldValue($values, 'paiement', 'field_activite', ['target_id' => $activite_id]);
    $this->setFieldValue($values, 'paiement', 'field_montant_du', $montant_du);
    $this->setFieldValue($values, 'paiement', 'field_montant_paye', $montant);
    $this->setFieldValue($values, 'paiement', 'field_date_paiement', $data['date_paiement'] ?? date('Y-m-d'));
    $this->setFieldValue($values, 'paiement', 'field_mode_paiement', $data['mode_paiement'] ?? 'especes');
    $this->setFieldValue($values, 'paiement', 'field_numero_recu', $data['numero_recu'] ?? $this->generateReceiptNumber());

    $node = Node::create($values);
    $node->save();

    $paiements = $this->loadPaiementsByEleve($activite_id);
    $total_paye = $paiements[$eleve_id]['montant_paye'] ?? $montant;
    $this->syncParticipationStatut($activite_id, $eleve_id, $montant_du, $total_paye);

    return $this->getActiviteDetail($activite_id);
  }

  protected function formatActiviteSummary(Node $node): array {
    $activite_id = (int) $node->id();
    $annee_scolaire = $this->getActiviteAnneeScolaire($node);
    $participations = $this->loadParticipations($activite_id, $annee_scolaire);
    $paiements = $this->loadPaiementsByEleve($activite_id);
    $collecte = array_sum(array_map(fn($p) => $p['montant_paye'], $paiements));
    $montant_participation = $this->getIntField($node, 'field_montant_participation');
    $objectif = $this->getIntField($node, 'field_objectif_collecte');
    if ($objectif <= 0) {
      $objectif = $montant_participation * max(count($participations), 1);
    }

    return [
      'id' => $activite_id,
      'title' => $node->label(),
      'type_activite' => $this->getListFieldValue($node, 'field_type_activite'),
      'type_activite_label' => $this->getListFieldLabel($node, 'field_type_activite'),
      'date_activite' => $this->getDateFieldValue($node, 'field_date_activite'),
      'date_limite_inscription' => $this->getDateFieldValue($node, 'field_date_limite_inscription'),
      'montant_participation' => $montant_participation,
      'objectif_collecte' => $this->getIntField($node, 'field_objectif_collecte'),
      'participation_obligatoire' => (bool) $this->getBoolField($node, 'field_participation_obligatoire'),
      'statut_activite' => $this->getListFieldValue($node, 'field_statut_activite'),
      'statut_activite_label' => $this->getListFieldLabel($node, 'field_statut_activite'),
      'annee_scolaire' => $annee_scolaire,
      'classes_cibles' => $this->getTermLabels($node, 'field_classes_cibles'),
      'classes_cibles_ids' => $this->getTermIds($node, 'field_classes_cibles'),
      'description' => $node->hasField('field_description') ? (string) $node->get('field_description')->value : '',
      'inscrits' => count($participations),
      'collecte' => $collecte,
      'objectif' => $objectif,
      'taux_collecte' => $objectif > 0 ? (int) round(($collecte / $objectif) * 100) : 0,
      'url' => '/app/activites/' . $activite_id,
    ];
  }

  protected function formatActiviteDetail(Node $node, int $collecte, int $objectif, int $inscrits): array {
    return $this->formatActiviteSummary($node) + [
      'collecte' => $collecte,
      'objectif' => $objectif,
      'inscrits' => $inscrits,
      'taux_collecte' => $objectif > 0 ? (int) round(($collecte / $objectif) * 100) : 0,
    ];
  }

  protected function loadParticipations(int $activite_id, ?string $annee_scolaire = NULL): array {
    if (!$this->bundleExists('participation_activite')) {
      return [];
    }

    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'participation_activite')
      ->condition('field_activite', $activite_id)
      ->accessCheck(FALSE)
      ->sort('created', 'ASC')
      ->execute();

    $rows = [];
    $statut_labels = $this->getListOptions('participation_activite', 'field_statut_participation');

    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $eleve = $node->get('field_eleve')->entity;
      if (!$eleve) {
        continue;
      }
      $statut = $this->getListFieldValue($node, 'field_statut_participation') ?: 'inscrit';
      $rows[] = [
        'id' => (int) $node->id(),
        'eleve_id' => (int) $eleve->id(),
        'eleve' => trim($eleve->get('field_nom')->value . ' ' . $eleve->get('field_prenom')->value) ?: $eleve->label(),
        'classe' => $this->getEleveClasseLabel($eleve, $annee_scolaire),
        'statut_inscription' => $statut,
        'statut_inscription_label' => $statut_labels[$statut] ?? $statut,
      ];
    }

    return $rows;
  }

  protected function loadPaiementsByEleve(int $activite_id): array {
    if (!$this->bundleExists('paiement')) {
      return [];
    }

    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'paiement')
      ->condition('field_activite', $activite_id)
      ->accessCheck(FALSE)
      ->execute();

    $by_eleve = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $eleve_id = (int) $node->get('field_eleve')->target_id;
      if ($eleve_id <= 0) {
        continue;
      }
      if (!isset($by_eleve[$eleve_id])) {
        $by_eleve[$eleve_id] = [
          'montant_paye' => 0,
          'date_paiement' => NULL,
          'numero_recu' => NULL,
        ];
      }
      $by_eleve[$eleve_id]['montant_paye'] += $this->getIntField($node, 'field_montant_paye');
      $date = $this->getDateFieldValue($node, 'field_date_paiement');
      if ($date) {
        $by_eleve[$eleve_id]['date_paiement'] = $date;
      }
      $recu = $node->hasField('field_numero_recu') ? (string) $node->get('field_numero_recu')->value : '';
      if ($recu !== '') {
        $by_eleve[$eleve_id]['numero_recu'] = $recu;
      }
    }

    return $by_eleve;
  }

  protected function syncParticipationStatut(int $activite_id, int $eleve_id, int $montant_du, int $montant_paye): void {
    if (!$this->bundleExists('participation_activite')) {
      return;
    }
    $est_confirme = $montant_du > 0 ? $montant_paye >= $montant_du : $montant_paye > 0;
    if (!$est_confirme) {
      return;
    }
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'participation_activite')
      ->condition('field_activite', $activite_id)
      ->condition('field_eleve', $eleve_id)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if (empty($ids)) {
      return;
    }
    $participation = Node::load((int) reset($ids));
    if (!$participation || !$participation->hasField('field_statut_participation')) {
      return;
    }
    if ($participation->get('field_statut_participation')->value !== 'confirme') {
      $participation->set('field_statut_participation', 'confirme');
      $participation->save();
    }
  }

  protected function participationExists(int $activite_id, int $eleve_id): bool {
    if (!$this->bundleExists('participation_activite')) {
      return FALSE;
    }
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'participation_activite')
      ->condition('field_activite', $activite_id)
      ->condition('field_eleve', $eleve_id)
      ->accessCheck(FALSE)
      ->execute();
    return !empty($ids);
  }

  protected function resolvePaiementStatut(int $montant_paye, int $montant_du): string {
    if ($montant_paye <= 0) {
      return 'non_paye';
    }
    if ($montant_du > 0 && $montant_paye < $montant_du) {
      return 'partiel';
    }
    return 'paye';
  }

  protected function resolvePaiementLabel(int $montant_paye, int $montant_du): string {
    return [
      'non_paye' => 'Non payé',
      'partiel' => 'Partiellement payé',
      'paye' => 'Payé',
    ][$this->resolvePaiementStatut($montant_paye, $montant_du)];
  }

  protected function getEleveClasseLabel(Node $eleve, ?string $annee_scolaire = NULL): string {
    $annee = $annee_scolaire ?: $this->getActiveSchoolYear();
    if (!$annee) {
      return '—';
    }
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_eleve', $eleve->id())
      ->condition('field_annee_scolaire', $annee)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if (empty($ids)) {
      return '—';
    }
    $inscription = Node::load((int) reset($ids));
    $classe = $inscription?->get('field_classe')->entity;
    return $classe ? $classe->label() : '—';
  }

  protected function getActiviteAnneeScolaire(Node $activite): ?string {
    if ($activite->hasField('field_annee_scolaire') && !$activite->get('field_annee_scolaire')->isEmpty()) {
      return (string) $activite->get('field_annee_scolaire')->value;
    }
    return $this->getActiveSchoolYear();
  }

  protected function getSchoolYearOptions(): array {
    if (!$this->bundleExists('inscription') || !$this->fieldExists('inscription', 'field_annee_scolaire')) {
      return [];
    }
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'inscription')['field_annee_scolaire'];
    $years = array_keys($field->getSettings()['allowed_values'] ?? []);
    rsort($years);
    return $years;
  }

  protected function getEleveInscription(int $eleve_id, ?string $annee_scolaire): ?Node {
    if (!$annee_scolaire) {
      return NULL;
    }
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_eleve', $eleve_id)
      ->condition('field_annee_scolaire', $annee_scolaire)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if (empty($ids)) {
      return NULL;
    }
    return Node::load((int) reset($ids));
  }

  protected function eleveHasInscriptionForYear(int $eleve_id, ?string $annee_scolaire): bool {
    return $this->getEleveInscription($eleve_id, $annee_scolaire) !== NULL;
  }

  protected function validateEleveForActivite(Node $activite, int $eleve_id): ?string {
    $annee_scolaire = $this->getActiviteAnneeScolaire($activite);
    if ($annee_scolaire && !$this->eleveHasInscriptionForYear($eleve_id, $annee_scolaire)) {
      return "L'élève doit être inscrit pour l'année $annee_scolaire.";
    }

    $classes_cibles = $this->getTermIds($activite, 'field_classes_cibles');
    if (empty($classes_cibles)) {
      return NULL;
    }

    $inscription = $this->getEleveInscription($eleve_id, $annee_scolaire);
    if (!$inscription) {
      return "Inscription introuvable pour cette année.";
    }

    $classe_tid = (int) $inscription->get('field_classe')->target_id;
    if ($classe_tid <= 0 || !in_array($classe_tid, $classes_cibles, TRUE)) {
      return "L'élève n'appartient pas aux classes ciblées par cette activité.";
    }

    return NULL;
  }

  protected function getClassesList(): array {
    $ids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'classe')
      ->accessCheck(FALSE)
      ->sort('name', 'ASC')
      ->execute();
    $items = [];
    foreach (Term::loadMultiple(array_values($ids)) as $term) {
      $items[] = ['id' => (int) $term->id(), 'nom' => $term->label()];
    }
    return $items;
  }

  protected function getTermLabels(Node $node, string $field): array {
    if (!$node->hasField($field)) {
      return [];
    }
    $labels = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      $labels[] = $term->label();
    }
    return $labels;
  }

  protected function getTermIds(Node $node, string $field): array {
    if (!$node->hasField($field)) {
      return [];
    }
    $ids = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      $ids[] = (int) $term->id();
    }
    return $ids;
  }

  protected function bundleExists(string $bundle): bool {
    return (bool) \Drupal::entityTypeManager()->getStorage('node_type')->load($bundle);
  }

  protected function fieldExists(string $bundle, string $field): bool {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle);
    return isset($definitions[$field]);
  }

  protected function setFieldValue(array &$values, string $bundle, string $field, $value): void {
    if (!$this->fieldExists($bundle, $field) || $value === NULL || $value === '') {
      return;
    }
    $values[$field] = is_array($value) ? $value : $value;
  }

  protected function getListOptions(string $bundle, string $field): array {
    if (!$this->bundleExists($bundle) || !$this->fieldExists($bundle, $field)) {
      return [];
    }
    $definition = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle)[$field];
    return $definition->getSettings()['allowed_values'] ?? [];
  }

  protected function getListFieldValue(Node $node, string $field): ?string {
    return $node->hasField($field) ? (string) $node->get($field)->value : NULL;
  }

  protected function getListFieldLabel(Node $node, string $field): ?string {
    $value = $this->getListFieldValue($node, $field);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $options = $this->getListOptions($node->bundle(), $field);
    return $options[$value] ?? $value;
  }

  protected function getDateFieldValue(Node $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $item = $node->get($field)->first();
    if ($item && $item->date) {
      return $item->date->format('Y-m-d');
    }
    $value = $node->get($field)->value;
    return $value ? substr((string) $value, 0, 10) : NULL;
  }

  protected function getIntField(Node $node, string $field): int {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return 0;
    }
    return (int) $node->get($field)->value;
  }

  protected function getBoolField(Node $node, string $field): bool {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return FALSE;
    }
    return (bool) $node->get($field)->value;
  }

  protected function generateReceiptNumber(): string {
    return 'REC-ACT-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
  }

  protected function getActiveSchoolYear(): ?string {
    $configured = trim((string) \Drupal::service('carnet_henitsoa')->getConfiguredSchoolYear());
    return $configured !== '' ? $configured : NULL;
  }

  protected function defaultTypeActiviteOptions(): array {
    return [
      'fete_ecole' => "Fête de l'école",
      'voyage_etude' => "Voyage d'étude",
      'fete_noel' => 'Fête de Noël',
      'autre' => 'Autre',
    ];
  }

  protected function defaultStatutActiviteOptions(): array {
    return [
      'planifiee' => 'Planifiée',
      'en_cours' => 'En cours',
      'terminee' => 'Terminée',
      'annulee' => 'Annulée',
    ];
  }

  protected function defaultStatutParticipationOptions(): array {
    return [
      'inscrit' => 'Inscrit',
      'confirme' => 'Confirmé',
      'annule' => 'Annulé',
    ];
  }

}
