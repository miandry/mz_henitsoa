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
use Symfony\Component\HttpFoundation\Response;
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
    $annee_scolaire = trim((string) $request->query->get('annee_scolaire', ''));

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->accessCheck(FALSE);
    if ($annee_scolaire !== '') {
      $query->condition('field_annee_scolaire', $annee_scolaire);
    }
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
        'montant' => $this->getInscriptionMontantValue($node),
      ];
    }

    return new JsonResponse([
      'status' => 'success',
      'annee_scolaire' => $annee_scolaire !== '' ? $annee_scolaire : NULL,
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
   * Dashboard statistics for the current school year.
   *
   * Aggregates live data from inscriptions, students and ecolages. Sections
   * without backing entities (presence, some alerts) return static samples
   * flagged with source = "static".
   */
  public function getDashboardStats() {
    $school_years = $this->getSchoolYearOptions();
    $default_year = $this->getCurrentSchoolYear() ?: ($school_years[0] ?? NULL);
    $annee_scolaire = $default_year;
    $cid = 'mz_henitsoa:dashboard:' . md5((string) $annee_scolaire);
    $cache = \Drupal::cache()->get($cid);
    if ($cache) {
      return new JsonResponse($cache->data);
    }

    $payload = $this->buildDashboardPayload($annee_scolaire, $school_years);
    \Drupal::cache()->set(
      $cid,
      $payload,
      \Drupal::time()->getRequestTime() + 300,
      ['node_list', 'taxonomy_term_list']
    );
    return new JsonResponse($payload);
  }

  /**
   * Builds the full dashboard API payload.
   */
  protected function buildDashboardPayload(?string $annee_scolaire, array $school_years): array {
    $inscription_ids = [];
    if ($annee_scolaire) {
      $inscription_ids = \Drupal::entityQuery('node')
        ->condition('type', 'inscription')
        ->condition('field_annee_scolaire', $annee_scolaire)
        ->accessCheck(FALSE)
        ->sort('created', 'DESC')
        ->execute();
    }

    $genres = $this->getAllowedValues('etudiant', 'field_genre');
    $by_genre = [];
    foreach ($genres as $key => $label) {
      $by_genre[(string) $key] = ['key' => (string) $key, 'label' => $label, 'count' => 0];
    }

    $by_adresse = [];
    $by_classe = [];
    $preview_items = [];
    $nouveaux_items = [];
    $nouveaux_count = 0;
    $nouvelles_inscriptions_mois = 0;
    $retard_paiement = [];
    $dossiers_incomplets = [];
    $mois_terms = $this->loadAllMoisTerms();
    $ecolage_suivi = \Drupal::service('mz_henitsoa.ecolage_suivi');
    $previous_school_month = $ecolage_suivi->getPreviousSchoolMonth($mois_terms, $annee_scolaire);
    $month_start = strtotime(date('Y-m-01 00:00:00'));

    foreach (Node::loadMultiple(array_values($inscription_ids)) as $node) {
      $classe = $node->get('field_classe')->entity;
      $eleve = $node->get('field_eleve')->entity;
      $classe_label = $classe ? $classe->label() : 'Non assignée';
      $classe_key = $classe ? (string) $classe->id() : '_none';

      if (!isset($by_classe[$classe_key])) {
        $by_classe[$classe_key] = [
          'classe' => $classe_label,
          'count' => 0,
          'id' => $classe ? (int) $classe->id() : NULL,
        ];
      }
      $by_classe[$classe_key]['count']++;

      if ($node->getCreatedTime() >= $month_start) {
        $nouvelles_inscriptions_mois++;
      }

      if (count($preview_items) < 8) {
        $preview_items[] = [
          'id' => (int) $node->id(),
          'matricule' => $node->label(),
          'classe' => $classe_label !== 'Non assignée' ? $classe_label : NULL,
          'photo_url' => $eleve ? $this->getStudentPhotoUrl($eleve) : NULL,
        ];
      }

      if (
        $previous_school_month
        && !$ecolage_suivi->isInscriptionMonthPaid($node, $previous_school_month['id'])
        && count($retard_paiement) < 5
      ) {
        $retard_paiement[] = [
          'type' => 'retard_paiement',
          'label' => 'Retard écolage',
          'eleve_id' => $eleve ? (int) $eleve->id() : NULL,
          'inscription_id' => (int) $node->id(),
          'nom' => $node->label(),
          'classe' => $classe_label,
          'detail' => $previous_school_month['nom'] . ' non payé',
          'url' => '/app/eleves-inscrits/' . $node->id(),
          'source' => 'live',
        ];
      }

      if (!$eleve) {
        continue;
      }

      $genre_value = (string) $eleve->get('field_genre')->value;
      if (isset($by_genre[$genre_value])) {
        $by_genre[$genre_value]['count']++;
      }

      $adresse = trim((string) $eleve->get('field_adresse')->value);
      $adresse_key = $adresse === '' ? 'Non renseignée' : $adresse;
      if (!isset($by_adresse[$adresse_key])) {
        $by_adresse[$adresse_key] = ['adresse' => $adresse_key, 'count' => 0];
      }
      $by_adresse[$adresse_key]['count']++;

      $missing = [];
      if ($adresse === '') {
        $missing[] = 'adresse';
      }
      if (trim((string) $eleve->get('field_phone')->value) === '') {
        $missing[] = 'téléphone';
      }
      if (!$this->getStudentPhotoUrl($eleve)) {
        $missing[] = 'photo';
      }
      if (!empty($missing) && count($dossiers_incomplets) < 5) {
        $dossiers_incomplets[] = [
          'type' => 'dossier_incomplet',
          'label' => 'Dossier incomplet',
          'eleve_id' => (int) $eleve->id(),
          'nom' => trim($eleve->get('field_nom')->value . ' ' . $eleve->get('field_prenom')->value),
          'classe' => $classe_label,
          'detail' => 'Manque : ' . implode(', ', $missing),
          'url' => '/app/eleves-inscrits/' . $node->id(),
          'source' => 'live',
        ];
      }

      if ($annee_scolaire && $this->isNewStudentForYear((int) $eleve->id(), $annee_scolaire, $school_years)) {
        $nouveaux_count++;
        if (count($nouveaux_items) < 8) {
          $nouveaux_items[] = [
            'id' => (int) $eleve->id(),
            'inscription_id' => (int) $node->id(),
            'matricule' => $eleve->label(),
            'nom' => $eleve->get('field_nom')->value,
            'prenom' => $eleve->get('field_prenom')->value,
            'classe' => $classe_label !== 'Non assignée' ? $classe_label : NULL,
            'adresse' => $eleve->get('field_adresse')->value,
            'date_entre' => $eleve->get('field_date_entre')->value,
            'photo_url' => $this->getStudentPhotoUrl($eleve),
          ];
        }
      }
    }

    $par_adresse = array_values($by_adresse);
    usort($par_adresse, fn($a, $b) => $b['count'] <=> $a['count']);

    $par_classe = array_values($by_classe);
    usort($par_classe, fn($a, $b) => $b['count'] <=> $a['count']);

    $annee_index = array_search($annee_scolaire, $school_years, TRUE);
    $annee_precedente = ($annee_index !== FALSE && isset($school_years[$annee_index + 1]))
      ? $school_years[$annee_index + 1]
      : NULL;

    $evolution = [];
    foreach (array_slice($school_years, 0, 2) as $year) {
      if (!$year) {
        continue;
      }
      $count = \Drupal::entityQuery('node')
        ->condition('type', 'inscription')
        ->condition('field_annee_scolaire', $year)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $evolution[] = ['annee' => $year, 'count' => (int) $count];
    }
    $evolution = array_reverse($evolution);

    $finances = $this->buildDashboardFinances($annee_scolaire, count($inscription_ids));
    $activite = $this->buildDashboardActivity();
    $alerts = array_merge(
      $retard_paiement,
      $dossiers_incomplets,
      $this->getDashboardStaticAlerts()
    );

    $frais_collectes = $finances['collecte_annee'];
    $frais_attendus = $finances['attendu_annee'];
    $frais_pct = $frais_attendus > 0 ? (int) round(($frais_collectes / $frais_attendus) * 100) : 0;

    return [
      'status' => 'success',
      'annee_scolaire' => $annee_scolaire,
      'annees_scolaires' => $school_years,
      'annee_precedente' => $annee_precedente,
      'key_stats' => [
        'total_inscrits' => count($inscription_ids),
        'par_classe' => $par_classe,
        'nouvelles_inscriptions_mois' => $nouvelles_inscriptions_mois,
        'nouvelles_inscriptions_annee' => $nouveaux_count,
        'presence_jour' => ['value' => 92, 'label' => '92 %', 'source' => 'static'],
        'frais_collectes' => $frais_collectes,
        'frais_attendus' => $frais_attendus,
        'frais_pct' => $frais_pct,
      ],
      'total_inscrits' => count($inscription_ids),
      'nouveaux_etudiants' => $nouveaux_count,
      'par_genre' => array_values($by_genre),
      'par_adresse' => $par_adresse,
      'par_classe' => $par_classe,
      'inscriptions_evolution' => $evolution,
      'alerts' => $alerts,
      'finances' => $finances,
      'activite_recente' => $activite,
      'nouveaux_items' => $nouveaux_items,
      'preview_items' => $preview_items,
      'modules' => [
        'presence' => FALSE,
        'finances_detail' => TRUE,
      ],
    ];
  }

  /**
   * Finance block for the dashboard (live ecolage + static categories).
   */
  protected function buildDashboardFinances(?string $annee_scolaire, int $total_inscrits): array {
    $collecte_annee = 0;
    $collecte_mois = 0;
    $ecolage_total = 0;
    $inscription_total = 0;
    $derniers_paiements = [];
    $month_start = strtotime(date('Y-m-01 00:00:00'));

    if ($annee_scolaire) {
      $ecolage_ids = \Drupal::entityQuery('node')
        ->condition('type', 'ecolage')
        ->condition('field_annee_scolaire', $annee_scolaire)
        ->accessCheck(FALSE)
        ->sort('created', 'DESC')
        ->execute();

      foreach (Node::loadMultiple(array_values($ecolage_ids)) as $node) {
        $montant = (int) $node->get('field_montant')->value;
        $ecolage_total += $montant;
        $collecte_annee += $montant;
        if ($node->getCreatedTime() >= $month_start) {
          $collecte_mois += $montant;
        }
        if (count($derniers_paiements) < 5) {
          $inscrit = $node->get('field_inscrit')->entity;
          $mois = $node->get('field_mois')->entity;
          $derniers_paiements[] = [
            'id' => (int) $node->id(),
            'eleve' => $inscrit ? $inscrit->label() : '—',
            'mois' => $mois ? $mois->label() : '—',
            'montant' => $montant,
            'date' => date('Y-m-d', $node->getCreatedTime()),
            'url' => '/app/suivi-ecolages/' . $node->id(),
            'source' => 'live',
          ];
        }
      }

      $inscription_ids = \Drupal::entityQuery('node')
        ->condition('type', 'inscription')
        ->condition('field_annee_scolaire', $annee_scolaire)
        ->accessCheck(FALSE)
        ->execute();
      foreach (Node::loadMultiple(array_values($inscription_ids)) as $node) {
        $montant = (int) ($this->getInscriptionMontantValue($node) ?? 0);
        $inscription_total += $montant;
        $collecte_annee += $montant;
      }
    }

    $objectif_mois = max($collecte_mois, $total_inscrits * 25000);
    $attendu_annee = max($collecte_annee, $total_inscrits * 12 * 25000);

    $par_categorie = [
      ['categorie' => 'Écolage', 'montant' => $ecolage_total, 'source' => 'live'],
      ['categorie' => 'Inscription', 'montant' => $inscription_total, 'source' => 'live'],
      ['categorie' => 'Cantine', 'montant' => 0, 'source' => 'static'],
      ['categorie' => 'Transport', 'montant' => 0, 'source' => 'static'],
      ['categorie' => 'Uniforme', 'montant' => 0, 'source' => 'static'],
    ];

    return [
      'encaisse_mois' => $collecte_mois,
      'objectif_mois' => $objectif_mois,
      'objectif_mois_source' => $collecte_mois > 0 ? 'live' : 'static',
      'collecte_annee' => $collecte_annee,
      'attendu_annee' => $attendu_annee,
      'par_categorie' => $par_categorie,
      'derniers_paiements' => $derniers_paiements,
    ];
  }

  /**
   * Recent activity feed from latest nodes.
   */
  protected function buildDashboardActivity(): array {
    $items = [];
    $types = [
      'etudiant' => ['label' => 'Élève ajouté', 'url_prefix' => '/app/archives-eleves/'],
      'inscription' => ['label' => 'Inscription', 'url_prefix' => '/app/eleves-inscrits/'],
      'ecolage' => ['label' => 'Paiement écolage', 'url_prefix' => '/app/suivi-ecolages/'],
    ];

    foreach ($types as $bundle => $meta) {
      $ids = \Drupal::entityQuery('node')
        ->condition('type', $bundle)
        ->accessCheck(FALSE)
        ->sort('created', 'DESC')
        ->range(0, 4)
        ->execute();
      foreach (Node::loadMultiple(array_values($ids)) as $node) {
        $items[] = [
          'type' => $bundle,
          'label' => $meta['label'],
          'title' => $node->label(),
          'date' => date('Y-m-d H:i', $node->getCreatedTime()),
          'timestamp' => $node->getCreatedTime(),
          'url' => $this->resolveDashboardActivityUrl($node, $meta['url_prefix']),
          'source' => 'live',
        ];
      }
    }

    usort($items, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return array_slice(array_map(function ($item) {
      unset($item['timestamp']);
      return $item;
    }, $items), 0, 10);
  }

  /**
   * Resolves dashboard activity links to inscription detail when possible.
   */
  protected function resolveDashboardActivityUrl(Node $node, string $default_prefix): string {
    if ($node->bundle() === 'inscription') {
      return '/app/eleves-inscrits/' . $node->id();
    }

    if ($node->bundle() === 'etudiant') {
      $annee_scolaire = $this->getCurrentSchoolYear();
      if ($annee_scolaire) {
        $ids = \Drupal::entityQuery('node')
          ->condition('type', 'inscription')
          ->condition('field_eleve', $node->id())
          ->condition('field_annee_scolaire', $annee_scolaire)
          ->accessCheck(FALSE)
          ->sort('created', 'DESC')
          ->range(0, 1)
          ->execute();
        if (!empty($ids)) {
          return '/app/eleves-inscrits/' . (int) reset($ids);
        }
      }
    }

    return $default_prefix . $node->id();
  }

  /**
   * Static alert samples for modules not yet in the system.
   */
  protected function getDashboardStaticAlerts(): array {
    return [
      [
        'type' => 'absence',
        'label' => 'Absence non justifiée',
        'nom' => 'Rakoto Jean',
        'classe' => '3ème A',
        'detail' => 'Absent le ' . date('d/m/Y', strtotime('-1 day')),
        'url' => '/app/eleves-inscrits',
        'source' => 'static',
      ],
      [
        'type' => 'echeance',
        'label' => 'Échéance proche',
        'nom' => 'Conseil de classe',
        'classe' => 'Tous niveaux',
        'detail' => 'Dans 5 jours — ' . date('d/m/Y', strtotime('+5 days')),
        'url' => '/app/dashboard',
        'source' => 'static',
      ],
    ];
  }

  /**
   * Whether a student has no inscription before the given school year.
   */
  protected function isNewStudentForYear(int $eleve_nid, string $current_year, array $school_years): bool {
    $index = array_search($current_year, $school_years, TRUE);
    $previous_years = $index !== FALSE ? array_slice($school_years, $index + 1) : [];
    if (empty($previous_years)) {
      return TRUE;
    }
    $prior = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_eleve', $eleve_nid)
      ->condition('field_annee_scolaire', $previous_years, 'IN')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    return $prior === 0;
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
   * Returns reference data for the inscription creation form.
   */
  public function getInscriptionFormOptions() {
    $droits = [];
    $droit_field = $this->getInscriptionDroitFieldName();
    if ($droit_field) {
      foreach ($this->getAllowedValues('inscription', $droit_field) as $value => $label) {
        $droits[] = ['value' => (string) $value, 'label' => $label];
      }
    }

    return new JsonResponse([
      'status' => 'success',
      'annee_scolaire' => $this->getCurrentSchoolYear(),
      'annees_scolaires' => $this->getSchoolYearOptions(),
      'droits_inscription' => $droits,
    ]);
  }

  /**
   * Creates a new "inscription" node.
   *
   * Expects a JSON body: {"eleve_nid": N, "classe_tid": N,
   * "annee_scolaire": "2025 -2026", "droit_inscription": "1",
   * "date_de_payement": "YYYY-MM-DD", "montant": 30000}.
   * Payment date is saved to field_date_payment, amount to field_montant.
   * Rejects duplicates (same student + school year).
   */
  public function createInscription(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $eleve_nid = (int) ($data['eleve_nid'] ?? 0);
    $classe_tid = (int) ($data['classe_tid'] ?? 0);
    $annee_scolaire = trim((string) ($data['annee_scolaire'] ?? ''));
    $droit_inscription = trim((string) ($data['droit_inscription'] ?? ''));
    $date_de_payement = trim((string) ($data['date_de_payement'] ?? $data['date_payment'] ?? ''));
    $montant = (int) ($data['montant'] ?? -1);

    if (!$eleve_nid || !$classe_tid || $annee_scolaire === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Élève, classe et année scolaire sont requis.'], 422);
    }
    if ($droit_inscription === '') {
      return new JsonResponse(['status' => 'error', 'message' => "Le droit d'inscription est requis."], 422);
    }
    if ($date_de_payement === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'La date de paiement est requise.'], 422);
    }
    if ($montant < 0) {
      return new JsonResponse(['status' => 'error', 'message' => 'Le montant est requis.'], 422);
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

    $values = [
      'type' => 'inscription',
      'field_eleve' => ['target_id' => $eleve_nid],
      'field_classe' => ['target_id' => $classe_tid],
      'field_annee_scolaire' => $annee_scolaire,
      'status' => 1,
    ];
    $droit_field = $this->getInscriptionDroitFieldName();
    if ($droit_field) {
      $values[$droit_field] = $droit_inscription;
    }
    $payment_field = $this->getInscriptionPaymentDateFieldName();
    if ($payment_field) {
      $values[$payment_field] = $date_de_payement;
    }
    $montant_field = $this->getInscriptionMontantFieldName();
    if ($montant_field) {
      $values[$montant_field] = $montant;
    }

    $node = Node::create($values);
    $node->save();

    $node = Node::load($node->id());
    return new JsonResponse([
      'status' => 'success',
      'item' => $this->formatInscriptionListItem($node, $classe->label(), $eleve),
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
    $years = $this->getSchoolYearOptions();
    if (empty($years)) {
      return NULL;
    }

    $configured = trim((string) \Drupal::service('carnet_henitsoa')->getConfiguredSchoolYear());
    if ($configured !== '' && in_array($configured, $years, TRUE)) {
      return $configured;
    }

    $current_start_year = $this->getCurrentSchoolYearStart();
    $preferred_year = $this->findSchoolYearByStart($years, $current_start_year);
    if ($preferred_year !== NULL && $this->schoolYearHasInscriptions($preferred_year)) {
      return $preferred_year;
    }

    foreach ($years as $year) {
      $start_year = $this->parseSchoolYearStart($year);
      if ($start_year !== NULL && $start_year > $current_start_year) {
        continue;
      }
      if ($this->schoolYearHasInscriptions($year)) {
        return $year;
      }
    }

    foreach ($years as $year) {
      if ($this->schoolYearHasInscriptions($year)) {
        return $year;
      }
    }

    return $preferred_year ?? $years[0];
  }

  /**
   * Current school year starts in September.
   */
  private function getCurrentSchoolYearStart(): int {
    $month = (int) date('n');
    $year = (int) date('Y');
    return $month >= 9 ? $year : $year - 1;
  }

  private function findSchoolYearByStart(array $years, int $start_year): ?string {
    foreach ($years as $year) {
      if ($this->parseSchoolYearStart($year) === $start_year) {
        return $year;
      }
    }
    return NULL;
  }

  private function parseSchoolYearStart(string $year): ?int {
    if (preg_match('/(\d{4})\s*-\s*(\d{4})/', $year, $matches)) {
      return (int) $matches[1];
    }
    return NULL;
  }

  private function schoolYearHasInscriptions(string $year): bool {
    $count = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_annee_scolaire', $year)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    return $count > 0;
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
   * Suivi des écolages : liste filtrée par inscription avec soldes.
   */
  public function getEcolageSuivi(Request $request) {
    $service = \Drupal::service('mz_henitsoa.ecolage_suivi');
    return new JsonResponse($service->getSuivi($request));
  }

  /**
   * Export CSV du suivi écolages.
   */
  public function exportEcolageSuivi(Request $request) {
    $service = \Drupal::service('mz_henitsoa.ecolage_suivi');
    $csv = $service->exportCsv($request);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="suivi-ecolages.csv"',
    ]);
  }

  /**
   * Historique des paiements d'une inscription.
   */
  public function getEcolageSuiviHistory($inscription_id) {
    $service = \Drupal::service('mz_henitsoa.ecolage_suivi');
    $payload = $service->getInscriptionHistory((int) $inscription_id);
    $code = ($payload['status'] ?? '') === 'error' ? 404 : 200;
    return new JsonResponse($payload, $code);
  }

  /**
   * Creates one or more "ecolage" (tuition payment) nodes.
   *
   * Expects a JSON body: {"inscrit_nid": N, "mois_tid": N} or
   * {"inscrit_nid": N, "mois_tids": [N, N, ...],
   * "annee_scolaire": "2025 -2026", "montant": 30000, "status": "1"}.
   * Rejects duplicates (same inscription + month), mirroring
   * _unique_title_validate() in mz_henitsoa.module. Saving each node
   * automatically updates the inscription's field_ecolage_status via the
   * existing mz_henitsoa_node_insert() hook.
   */
  public function createEcolage(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $inscrit_nid = (int) ($data['inscrit_nid'] ?? 0);
    $annee_scolaire = trim((string) ($data['annee_scolaire'] ?? ''));
    $montant = (int) ($data['montant'] ?? 0);
    $status = (string) ($data['status'] ?? '1');
    $mois_tids = [];

    if (!empty($data['mois_tids']) && is_array($data['mois_tids'])) {
      $mois_tids = array_values(array_unique(array_filter(array_map('intval', $data['mois_tids']))));
    }
    elseif (!empty($data['mois_tid'])) {
      $mois_tids = [(int) $data['mois_tid']];
    }

    if (!$inscrit_nid || empty($mois_tids) || $annee_scolaire === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Inscription, au moins un mois et année scolaire sont requis.'], 422);
    }

    $inscrit = Node::load($inscrit_nid);
    if (!$inscrit || $inscrit->bundle() !== 'inscription') {
      return new JsonResponse(['status' => 'error', 'message' => "Inscription introuvable."], 422);
    }

    $mode_paiement = trim((string) ($data['mode_paiement'] ?? ''));
    $date_paiement = trim((string) ($data['date_paiement'] ?? date('Y-m-d')));
    $statuses = $this->getEcolageStatusOptions();
    $suivi_service = \Drupal::service('mz_henitsoa.ecolage_suivi');
    $items = [];
    $skipped = [];

    foreach ($mois_tids as $mois_tid) {
      $mois = Term::load($mois_tid);
      if (!$mois || $mois->bundle() !== 'mois') {
        return new JsonResponse(['status' => 'error', 'message' => "Mois introuvable (ID $mois_tid)."], 422);
      }

      $duplicate = \Drupal::service('mz_henitsoa.default')->checkifEcolageExist($inscrit_nid, $mois_tid);
      if ($duplicate) {
        $skipped[] = [
          'mois_tid' => $mois_tid,
          'mois' => $mois->label(),
          'message' => 'Un écolage pour ce mois existe déjà pour cette inscription.',
        ];
        continue;
      }

      $receipt = $suivi_service->generateReceiptNumber();
      $description_parts = ["Reçu: $receipt", "Date: $date_paiement"];
      if ($mode_paiement !== '') {
        $description_parts[] = 'Mode: ' . $mode_paiement;
      }

      $node = Node::create([
        'type' => 'ecolage',
        'title' => date('Ymd') . '_' . uniqid(),
        'field_inscrit' => ['target_id' => $inscrit_nid],
        'field_mois' => ['target_id' => $mois_tid],
        'field_annee_scolaire' => $annee_scolaire,
        'field_montant' => $montant,
        'field_status' => $status,
        'field_description' => implode(' | ', $description_parts),
        'status' => 1,
      ]);
      $node->save();

      $items[] = [
        'id' => (int) $node->id(),
        'eleve' => $inscrit->label(),
        'mois' => $mois->label(),
        'montant' => $montant,
        'status' => $status,
        'status_label' => $statuses[$status] ?? $status,
        'annee_scolaire' => $annee_scolaire,
        'receipt_number' => $receipt,
        'mode_paiement' => $mode_paiement,
        'date_paiement' => $date_paiement,
      ];
    }

    if (empty($items)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Aucun écolage créé. Tous les mois sélectionnés existent déjà pour cette inscription.',
        'skipped' => $skipped,
      ], 409);
    }

    $response = [
      'status' => 'success',
      'created' => count($items),
      'items' => $items,
      'item' => $items[0],
      'skipped' => $skipped,
    ];

    return new JsonResponse($response, 201);
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
   * Returns billing months (September to June) for écolage.
   *
   * @return array
   *   Array of ['id' => int, 'nom' => string].
   */
  protected function getMoisTerms() {
    return \Drupal::service('mz_henitsoa.ecolage_suivi')->getEcolageMoisTerms();
  }

  /**
   * Loads all 12 month taxonomy terms (including vacation months).
   *
   * @return array
   *   Array of ['id' => int, 'nom' => string].
   */
  protected function loadAllMoisTerms() {
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

    return new JsonResponse([
      'status' => 'success',
      'item' => $this->buildInscriptionDetailItem($node),
    ]);
  }

  /**
   * Updates an existing "inscription" node.
   *
   * Expects a JSON body with: classe_tid, annee_scolaire, droit_inscription,
   * date_de_payement (or date_payment), montant, and optional description.
   */
  public function updateInscription($id, Request $request) {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'inscription') {
      return new JsonResponse(['status' => 'error', 'message' => 'Inscription introuvable.'], 404);
    }

    $data = json_decode($request->getContent(), TRUE) ?: [];
    $classe_tid = (int) ($data['classe_tid'] ?? 0);
    $annee_scolaire = trim((string) ($data['annee_scolaire'] ?? ''));
    $droit_inscription = trim((string) ($data['droit_inscription'] ?? ''));
    $date_de_payement = trim((string) ($data['date_de_payement'] ?? $data['date_payment'] ?? ''));
    $montant = (int) ($data['montant'] ?? -1);
    $description = array_key_exists('description', $data) ? trim((string) $data['description']) : NULL;

    if (!$classe_tid || $annee_scolaire === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Classe et année scolaire sont requis.'], 422);
    }
    if ($droit_inscription === '') {
      return new JsonResponse(['status' => 'error', 'message' => "Le droit d'inscription est requis."], 422);
    }
    if ($date_de_payement === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'La date de paiement est requise.'], 422);
    }
    if ($montant < 0) {
      return new JsonResponse(['status' => 'error', 'message' => 'Le montant est requis.'], 422);
    }

    $classe = Term::load($classe_tid);
    if (!$classe || $classe->bundle() !== 'classe') {
      return new JsonResponse(['status' => 'error', 'message' => "Classe introuvable."], 422);
    }

    $eleve_nid = (int) $node->get('field_eleve')->target_id;
    $duplicate_ids = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_eleve', $eleve_nid)
      ->condition('field_annee_scolaire', $annee_scolaire)
      ->condition('nid', $node->id(), '<>')
      ->accessCheck(FALSE)
      ->execute();
    if (!empty($duplicate_ids)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => "Cet élève a déjà une inscription pour l'année $annee_scolaire.",
      ], 409);
    }

    $node->set('field_classe', ['target_id' => $classe_tid]);
    $node->set('field_annee_scolaire', $annee_scolaire);
    $droit_field = $this->getInscriptionDroitFieldName($node);
    if ($droit_field) {
      $node->set($droit_field, $droit_inscription);
    }
    $payment_field = $this->getInscriptionPaymentDateFieldName($node);
    if ($payment_field) {
      $node->set($payment_field, $date_de_payement);
    }
    $montant_field = $this->getInscriptionMontantFieldName($node);
    if ($montant_field) {
      $node->set($montant_field, $montant);
    }
    if ($description !== NULL && $node->hasField('field_description')) {
      $node->set('field_description', $description === '' ? NULL : $description);
    }

    $node->save();
    $node = Node::load($node->id());

    return new JsonResponse([
      'status' => 'success',
      'item' => $this->buildInscriptionDetailItem($node),
    ]);
  }

  /**
   * Builds the full inscription detail payload for API responses.
   */
  protected function buildInscriptionDetailItem($node) {
    $classe = $node->get('field_classe')->entity;
    $eleve = $node->get('field_eleve')->entity;
    $droit_field = $this->getInscriptionDroitFieldName($node);
    $droits = $droit_field ? $this->getAllowedValues('inscription', $droit_field) : [];
    $droits_value = $droit_field ? $node->get($droit_field)->value : NULL;
    $payment_field = $this->getInscriptionPaymentDateFieldName($node);
    $date_de_payement = $this->getInscriptionPaymentDateValue($node, $payment_field);

    $ecolage_months = $this->buildEcolageMonthsForInscription($node);

    return [
      'id' => (int) $node->id(),
      'matricule' => $node->label(),
      'annee_scolaire' => $node->get('field_annee_scolaire')->value,
      'classe' => $classe ? $classe->label() : NULL,
      'classe_tid' => $classe ? (int) $classe->id() : NULL,
      'description' => $node->get('field_description')->value,
      'droits_status' => $droits_value,
      'droits_status_label' => $droits[$droits_value] ?? NULL,
      'droit_inscription' => $droits_value,
      'droit_inscription_label' => $droits[$droits_value] ?? NULL,
      'date_de_payement' => $date_de_payement,
      'date_payment' => $date_de_payement,
      'montant' => $this->getInscriptionMontantValue($node),
      'eleve_nid' => $eleve ? (int) $eleve->id() : NULL,
      'eleve' => $eleve ? $this->formatEtudiant($eleve) : NULL,
      'ecolage_months' => $ecolage_months,
    ];
  }

  /**
   * Builds month-by-month ecolage status for an inscription.
   */
  protected function buildEcolageMonthsForInscription($node): array {
    $paid_month_ids = array_map(
      function ($item) { return (int) $item['target_id']; },
      $node->get('field_ecolage_status')->getValue()
    );
    $annee_scolaire = $node->get('field_annee_scolaire')->value;
    $statuses = $this->getEcolageStatusOptions();

    $ecolage_ids = \Drupal::entityQuery('node')
      ->condition('type', 'ecolage')
      ->condition('field_inscrit', $node->id())
      ->condition('field_annee_scolaire', $annee_scolaire)
      ->accessCheck(FALSE)
      ->execute();

    $payments_by_mois = [];
    foreach (Node::loadMultiple(array_values($ecolage_ids)) as $ecolage) {
      $mois_tid = (int) $ecolage->get('field_mois')->target_id;
      $status_value = $ecolage->get('field_status')->value;
      $payments_by_mois[$mois_tid] = [
        'id' => (int) $ecolage->id(),
        'montant' => (int) $ecolage->get('field_montant')->value,
        'status' => $status_value,
        'status_label' => $statuses[$status_value] ?? $status_value,
        'date' => date('Y-m-d', $ecolage->getCreatedTime()),
        'description' => $ecolage->get('field_description')->value,
        'url' => '/app/suivi-ecolages/' . $ecolage->id(),
      ];
    }

    return array_map(function ($mois) use ($paid_month_ids, $payments_by_mois) {
      return $mois + [
        'paid' => in_array($mois['id'], $paid_month_ids, TRUE),
        'payment' => $payments_by_mois[$mois['id']] ?? NULL,
      ];
    }, $this->getMoisTerms());
  }

  /**
   * Full detail of an archived "etudiant" node.
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
   * Formats an inscription node for list/create API responses.
   */
  protected function formatInscriptionListItem($node, $classe_label, $eleve) {
    $droit_field = $this->getInscriptionDroitFieldName($node);
    $droits = $droit_field ? $this->getAllowedValues('inscription', $droit_field) : [];
    $droits_value = $droit_field ? $node->get($droit_field)->value : NULL;
    $payment_field = $this->getInscriptionPaymentDateFieldName($node);
    $date_de_payement = $this->getInscriptionPaymentDateValue($node, $payment_field);

    return [
      'id' => (int) $node->id(),
      'matricule' => $node->label(),
      'annee_scolaire' => $node->get('field_annee_scolaire')->value,
      'classe' => $classe_label,
      'eleve_nid' => $eleve ? (int) $eleve->id() : NULL,
      'photo_url' => $eleve ? $this->getStudentPhotoUrl($eleve) : NULL,
      'droit_inscription' => $droits_value,
      'droit_inscription_label' => $droits[$droits_value] ?? NULL,
      'date_de_payement' => $date_de_payement,
      'montant' => $this->getInscriptionMontantValue($node),
    ];
  }

  /**
   * Returns the inscription montant field value.
   */
  private function getInscriptionMontantValue($node): ?int {
    $montant_field = $this->getInscriptionMontantFieldName($node);
    if (!$montant_field || $node->get($montant_field)->isEmpty()) {
      return NULL;
    }
    return (int) $node->get($montant_field)->value;
  }

  /**
   * Returns the inscription payment date value (Y-m-d).
   */
  private function getInscriptionPaymentDateValue($node, ?string $payment_field): ?string {
    if (!$payment_field || $node->get($payment_field)->isEmpty()) {
      return NULL;
    }
    $item = $node->get($payment_field)->first();
    if ($item && $item->date) {
      return $item->date->format('Y-m-d');
    }
    $value = $node->get($payment_field)->value;
    return $value ? substr((string) $value, 0, 10) : NULL;
  }

  /**
   * Returns the inscription montant field name.
   */
  private function getInscriptionMontantFieldName($entity = NULL): ?string {
    $candidates = ['field_montant'];
    if ($entity) {
      foreach ($candidates as $field_name) {
        if ($entity->hasField($field_name)) {
          return $field_name;
        }
      }
      return NULL;
    }
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'inscription');
    foreach ($candidates as $field_name) {
      if (isset($definitions[$field_name])) {
        return $field_name;
      }
    }
    return NULL;
  }

  /**
   * Returns the inscription droit field name.
   */
  private function getInscriptionDroitFieldName($entity = NULL): ?string {
    $candidates = ['field_droit_inscription', 'field_droite'];
    if ($entity) {
      foreach ($candidates as $field_name) {
        if ($entity->hasField($field_name)) {
          return $field_name;
        }
      }
      return NULL;
    }
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'inscription');
    foreach ($candidates as $field_name) {
      if (isset($definitions[$field_name])) {
        return $field_name;
      }
    }
    return NULL;
  }

  /**
   * Returns the inscription payment date field name.
   */
  private function getInscriptionPaymentDateFieldName($entity = NULL): ?string {
    $candidates = ['field_date_payment', 'field_date_de_payement', 'field_date_de_paiement'];
    if ($entity) {
      foreach ($candidates as $field_name) {
        if ($entity->hasField($field_name)) {
          return $field_name;
        }
      }
      return NULL;
    }
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'inscription');
    foreach ($candidates as $field_name) {
      if (isset($definitions[$field_name])) {
        return $field_name;
      }
    }
    return NULL;
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
    if (!array_key_exists('template_path', $data) && !array_key_exists('selected_school_year', $data)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Aucune donnée de configuration reçue.'], 422);
    }

    $template_path = trim((string) ($data['template_path'] ?? ''));
    $selected_school_year = trim((string) ($data['selected_school_year'] ?? ''));
    $service = \Drupal::service('carnet_henitsoa');
    $school_years = $service->getSchoolYearOptions();

    if ($selected_school_year !== '' && !in_array($selected_school_year, $school_years, TRUE)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => "Année scolaire invalide.",
      ], 422);
    }

    if (array_key_exists('template_path', $data) && $template_path !== '' && !$service->resolveTemplatePath($template_path)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Fichier introuvable. Utilisez un chemin Drupal (public://...) ou un chemin absolu vers un fichier .docx.',
      ], 422);
    }

    if (array_key_exists('template_path', $data)) {
      $service->setConfiguredTemplatePath($template_path);
    }
    if (array_key_exists('selected_school_year', $data)) {
      $service->setConfiguredSchoolYear($selected_school_year);
    }

    return new JsonResponse([
      'status' => 'success',
      'item' => $service->getTemplateConfigInfo(),
    ]);
  }

  /**
   * JSON listing of school activities.
   */
  public function listActivites(Request $request) {
    $result = \Drupal::service('mz_henitsoa.activite')->listActivites($request);
    $status = ($result['status'] ?? '') === 'error' ? 400 : 200;
    return new JsonResponse($result, $status);
  }

  /**
   * Form options for activity management screens.
   */
  public function getActiviteFormOptions() {
    return new JsonResponse(\Drupal::service('mz_henitsoa.activite')->getFormOptions());
  }

  /**
   * Creates a new activity node.
   */
  public function createActivite(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $result = \Drupal::service('mz_henitsoa.activite')->createActivite($data);
    $status = ($result['status'] ?? '') === 'error' ? 422 : 201;
    return new JsonResponse($result, $status);
  }

  /**
   * Activity detail with participants, inscriptions and payments.
   */
  public function getActiviteDetail(int $id) {
    $result = \Drupal::service('mz_henitsoa.activite')->getActiviteDetail($id);
    $status = ($result['status'] ?? '') === 'error' ? 404 : 200;
    return new JsonResponse($result, $status);
  }

  /**
   * Updates an existing activity node.
   */
  public function updateActivite(int $id, Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $result = \Drupal::service('mz_henitsoa.activite')->updateActivite($id, $data);
    $status = ($result['status'] ?? '') === 'error' ? 422 : 200;
    return new JsonResponse($result, $status);
  }

  /**
   * Registers a student for an activity (inscription only).
   */
  public function createActiviteParticipation(int $id, Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $result = \Drupal::service('mz_henitsoa.activite')->createParticipation($id, $data);
    $status = ($result['status'] ?? '') === 'error' ? 422 : 201;
    return new JsonResponse($result, $status);
  }

  /**
   * Records a payment linked to an activity.
   */
  public function createActivitePaiement(int $id, Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $result = \Drupal::service('mz_henitsoa.activite')->createPaiement($id, $data);
    $status = ($result['status'] ?? '') === 'error' ? 422 : 201;
    return new JsonResponse($result, $status);
  }

}
