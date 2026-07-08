<?php

namespace Drupal\mz_henitsoa;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;

/**
 * Centralized ecolage balance and suivi logic.
 */
class EcolageSuiviService {

  const DEFAULT_MONTHLY_FEE = 25000;

  const RETARD_MOIS_SEUIL = 2;

  /**
   * Builds the full suivi payload for the Vue page.
   */
  public function getSuivi(Request $request, bool $all = FALSE): array {
    $classe_tid = (int) $request->query->get('classe_tid', 0);
    $statut = trim((string) $request->query->get('statut', ''));
    $mois_tid = (int) $request->query->get('mois_tid', 0);
    $search = trim((string) $request->query->get('search', ''));
    $sort = trim((string) $request->query->get('sort', 'solde'));
    $direction = strtolower((string) $request->query->get('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
    $page = max((int) $request->query->get('page', 0), 0);
    $limit = min(max((int) $request->query->get('limit', 25), 1), 100);

    $annee_scolaire = $this->getCurrentSchoolYear();
    $mois_terms = $this->getMoisTerms();
    $classes = $this->getClassesList();

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->accessCheck(FALSE);
    if ($annee_scolaire) {
      $query->condition('field_annee_scolaire', $annee_scolaire);
    }
    if ($classe_tid > 0) {
      $query->condition('field_classe', $classe_tid);
    }
    if ($search !== '') {
      $query->condition('title', $search, 'CONTAINS');
    }
    $inscription_ids = $query->sort('title', 'ASC')->execute();

    $rows = [];
    foreach (Node::loadMultiple(array_values($inscription_ids)) as $inscription) {
      $row = $this->computeInscriptionSuivi($inscription, $mois_terms, $annee_scolaire, $mois_tid);
      if ($statut !== '' && $row['statut'] !== $statut) {
        continue;
      }
      $rows[] = $row;
    }

    $summary = $this->buildSummary($rows);
    $alerts = $this->buildAlerts($rows, $mois_terms);

    $this->sortRows($rows, $sort, $direction);
    $total = count($rows);
    $rows_page = $all ? $rows : array_slice($rows, $page * $limit, $limit);

    return [
      'status' => 'success',
      'annee_scolaire' => $annee_scolaire,
      'filters' => [
        'classes' => $classes,
        'mois' => $mois_terms,
        'statuts' => [
          ['value' => 'a_jour', 'label' => 'À jour'],
          ['value' => 'partiel', 'label' => 'Partiellement payé'],
          ['value' => 'retard', 'label' => 'En retard'],
          ['value' => 'exonere', 'label' => 'Exonéré'],
        ],
      ],
      'config' => [
        'retard_mois_seuil' => self::RETARD_MOIS_SEUIL,
        'modes_paiement' => [
          ['value' => 'especes', 'label' => 'Espèces', 'source' => 'demo'],
          ['value' => 'mobile_money', 'label' => 'Mobile Money', 'source' => 'demo'],
          ['value' => 'virement', 'label' => 'Virement', 'source' => 'demo'],
        ],
        'sms_whatsapp' => ['available' => FALSE, 'source' => 'static'],
        'export_pdf' => ['available' => FALSE, 'source' => 'static'],
      ],
      'summary' => $summary,
      'alerts' => $alerts,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'items' => $rows_page,
    ];
  }

  /**
   * Payment history for one inscription.
   */
  public function getInscriptionHistory(int $inscription_id): array {
    $inscription = Node::load($inscription_id);
    if (!$inscription || $inscription->bundle() !== 'inscription') {
      return ['status' => 'error', 'message' => 'Inscription introuvable.'];
    }

    $annee_scolaire = $inscription->get('field_annee_scolaire')->value;
    $mois_terms = $this->getMoisTerms();
    $suivi = $this->computeInscriptionSuivi($inscription, $mois_terms, $annee_scolaire, 0);

    $ecolage_ids = \Drupal::entityQuery('node')
      ->condition('type', 'ecolage')
      ->condition('field_inscrit', $inscription_id)
      ->condition('field_annee_scolaire', $annee_scolaire)
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->execute();

    $statuses = $this->getEcolageStatusOptions();
    $paiements = [];
    foreach (Node::loadMultiple(array_values($ecolage_ids)) as $node) {
      $mois = $node->get('field_mois')->entity;
      $status_value = $node->get('field_status')->value;
      $paiements[] = [
        'id' => (int) $node->id(),
        'mois' => $mois ? $mois->label() : '—',
        'montant' => (int) $node->get('field_montant')->value,
        'status' => $status_value,
        'status_label' => $statuses[$status_value] ?? $status_value,
        'date' => date('Y-m-d', $node->getCreatedTime()),
        'description' => $node->get('field_description')->value,
        'url' => '/app/suivi-ecolages/' . $node->id(),
      ];
    }

    return [
      'status' => 'success',
      'item' => $suivi,
      'paiements' => $paiements,
      'mois_status' => $suivi['mois_status'],
    ];
  }

  /**
   * Exports filtered suivi rows as CSV.
   */
  public function exportCsv(Request $request): string {
    $data = $this->getSuivi($request, TRUE);
    $lines = ["Élève;Classe;Montant dû;Montant payé;Solde;Statut;Dernier paiement"];
    foreach ($data['items'] as $row) {
      $lines[] = implode(';', [
        $row['eleve'],
        $row['classe'],
        $row['montant_du'],
        $row['montant_paye'],
        $row['solde'],
        $row['statut_label'],
        $row['dernier_paiement'] ?? '',
      ]);
    }
    return implode("\n", $lines);
  }

  /**
   * Computes suivi row for one inscription.
   */
  public function computeInscriptionSuivi(Node $inscription, array $mois_terms, ?string $annee_scolaire, int $filter_mois_tid = 0): array {
    $classe = $inscription->get('field_classe')->entity;
    $eleve = $inscription->get('field_eleve')->entity;
    $paid_month_ids = array_map(
      fn($item) => (int) $item['target_id'],
      $inscription->get('field_ecolage_status')->getValue()
    );

    $period_months = $this->getPeriodMonths($mois_terms, $filter_mois_tid);
    $period_month_ids = array_column($period_months, 'id');
    $paid_in_period = count(array_intersect($paid_month_ids, $period_month_ids));
    $expected_months = $filter_mois_tid > 0 ? 1 : $this->getElapsedSchoolMonths(count($mois_terms));

    $monthly_fee = $this->resolveMonthlyFee($inscription, $annee_scolaire);
    $montant_du = ($filter_mois_tid > 0 ? 1 : min($expected_months, count($mois_terms))) * $monthly_fee;

    $ecolage_query = \Drupal::entityQuery('node')
      ->condition('type', 'ecolage')
      ->condition('field_inscrit', $inscription->id())
      ->accessCheck(FALSE);
    if ($annee_scolaire) {
      $ecolage_query->condition('field_annee_scolaire', $annee_scolaire);
    }
    if ($filter_mois_tid > 0) {
      $ecolage_query->condition('field_mois', $filter_mois_tid);
    }
    $ecolage_ids = $ecolage_query->sort('created', 'DESC')->execute();

    $montant_paye = 0;
    $dernier_paiement = NULL;
    foreach (Node::loadMultiple(array_values($ecolage_ids)) as $ecolage) {
      $montant_paye += (int) $ecolage->get('field_montant')->value;
      if ($dernier_paiement === NULL) {
        $dernier_paiement = date('Y-m-d', $ecolage->getCreatedTime());
      }
    }

    $solde = max(0, $montant_du - $montant_paye);
    $statut = $this->resolveStatut($paid_in_period, $expected_months, $montant_paye, $montant_du, $inscription);
    $statut_labels = [
      'a_jour' => 'À jour',
      'partiel' => 'Partiellement payé',
      'retard' => 'En retard',
      'exonere' => 'Exonéré',
    ];

    $mois_status = array_map(function ($mois) use ($paid_month_ids) {
      return $mois + ['paid' => in_array($mois['id'], $paid_month_ids, TRUE)];
    }, $mois_terms);

    $retard_mois = max(0, min($expected_months, count($period_month_ids)) - $paid_in_period);

    return [
      'inscription_id' => (int) $inscription->id(),
      'eleve_id' => $eleve ? (int) $eleve->id() : NULL,
      'eleve' => $inscription->label(),
      'classe' => $classe ? $classe->label() : '—',
      'classe_tid' => $classe ? (int) $classe->id() : NULL,
      'montant_du' => $montant_du,
      'montant_paye' => $montant_paye,
      'solde' => $solde,
      'statut' => $statut,
      'statut_label' => $statut_labels[$statut] ?? $statut,
      'dernier_paiement' => $dernier_paiement,
      'mois_payes' => $paid_in_period,
      'mois_attendus' => $filter_mois_tid > 0 ? 1 : min($expected_months, count($mois_terms)),
      'retard_mois' => $retard_mois,
      'monthly_fee' => $monthly_fee,
      'mois_status' => $mois_status,
      'url_inscription' => '/app/eleves-inscrits/' . $inscription->id(),
      'url_eleve' => $eleve ? '/app/archives-eleves/' . $eleve->id() : NULL,
    ];
  }

  /**
   * Generates a receipt number for new payments.
   */
  public function generateReceiptNumber(): string {
    return 'REC-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
  }

  protected function resolveStatut(int $paid_in_period, int $expected_months, int $montant_paye, int $montant_du, Node $inscription): string {
    if ($montant_du <= 0) {
      return 'a_jour';
    }
    if ($paid_in_period >= $expected_months || $montant_paye >= $montant_du) {
      return 'a_jour';
    }
    if ($paid_in_period > 0 || $montant_paye > 0) {
      return 'partiel';
    }
    return 'retard';
  }

  protected function resolveMonthlyFee(Node $inscription, ?string $annee_scolaire): int {
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'ecolage')
      ->condition('field_inscrit', $inscription->id())
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->range(0, 3)
      ->execute();
    $amounts = [];
    foreach (Node::loadMultiple(array_values($ids)) as $node) {
      $amounts[] = (int) $node->get('field_montant')->value;
    }
    if (!empty($amounts)) {
      return (int) round(array_sum($amounts) / count($amounts));
    }
    $montant_field = $inscription->hasField('field_montant') ? (int) ($inscription->get('field_montant')->value ?? 0) : 0;
    return $montant_field > 0 ? $montant_field : self::DEFAULT_MONTHLY_FEE;
  }

  protected function getPeriodMonths(array $mois_terms, int $filter_mois_tid): array {
    if ($filter_mois_tid > 0) {
      foreach ($mois_terms as $mois) {
        if ((int) $mois['id'] === $filter_mois_tid) {
          return [$mois];
        }
      }
      return [];
    }
    return $mois_terms;
  }

  protected function getElapsedSchoolMonths(int $total_months): int {
    $school_calendar = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8];
    $now = (int) date('n');
    $index = array_search($now, $school_calendar, TRUE);
    if ($index === FALSE) {
      return $total_months;
    }
    return min($index + 1, $total_months);
  }

  protected function buildSummary(array $rows): array {
    $attendu = array_sum(array_column($rows, 'montant_du'));
    $encaisse = array_sum(array_column($rows, 'montant_paye'));
    $retard_rows = array_filter($rows, fn($r) => $r['statut'] === 'retard' || $r['statut'] === 'partiel');
    $retard_montant = array_sum(array_map(fn($r) => $r['solde'], $retard_rows));
    $pct = $attendu > 0 ? (int) round(($encaisse / $attendu) * 100) : 0;

    return [
      'total_attendu' => $attendu,
      'total_encaisse' => $encaisse,
      'total_retard' => $retard_montant,
      'eleves_retard' => count($retard_rows),
      'taux_recouvrement' => $pct,
    ];
  }

  protected function buildAlerts(array $rows, array $mois_terms): array {
    $alerts = [];
    $seuil = self::RETARD_MOIS_SEUIL;
    $seen = [];

    foreach ($rows as $row) {
      $key = $row['inscription_id'];
      if (isset($seen[$key])) {
        continue;
      }
      if ($row['retard_mois'] >= $seuil) {
        $alerts[] = [
          'type' => 'retard_long',
          'label' => 'Retard prolongé',
          'nom' => $row['eleve'],
          'classe' => $row['classe'],
          'detail' => $row['retard_mois'] . ' mois de retard',
          'url' => '/app/eleves-inscrits/' . $row['inscription_id'],
          'source' => 'live',
        ];
        $seen[$key] = TRUE;
      }
      elseif ($row['mois_payes'] === 0 && $row['mois_attendus'] > 0) {
        $alerts[] = [
          'type' => 'aucun_paiement',
          'label' => 'Aucun paiement',
          'nom' => $row['eleve'],
          'classe' => $row['classe'],
          'detail' => 'Aucun écolage depuis le début de l\'année',
          'url' => '/app/eleves-inscrits/' . $row['inscription_id'],
          'source' => 'live',
        ];
        $seen[$key] = TRUE;
      }
      if (count($alerts) >= 8) {
        break;
      }
    }

    return $alerts;
  }

  protected function sortRows(array &$rows, string $sort, string $direction): void {
    $allowed = ['eleve', 'classe', 'montant_du', 'montant_paye', 'solde', 'statut', 'dernier_paiement'];
    if (!in_array($sort, $allowed, TRUE)) {
      $sort = 'solde';
    }
    usort($rows, function ($a, $b) use ($sort, $direction) {
      $va = $a[$sort] ?? '';
      $vb = $b[$sort] ?? '';
      $cmp = is_numeric($va) && is_numeric($vb) ? $va <=> $vb : strcasecmp((string) $va, (string) $vb);
      return $direction === 'asc' ? $cmp : -$cmp;
    });
  }

  protected function getCurrentSchoolYear(): ?string {
    $years = $this->getSchoolYearOptions();
    foreach ($years as $year) {
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
    return $years[0] ?? NULL;
  }

  protected function getSchoolYearOptions(): array {
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'inscription')['field_annee_scolaire'];
    $years = array_keys($field->getSettings()['allowed_values']);
    rsort($years);
    return $years;
  }

  protected function getMoisTerms(): array {
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

  protected function getEcolageStatusOptions(): array {
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'ecolage')['field_status'];
    return $field->getSettings()['allowed_values'];
  }

}
