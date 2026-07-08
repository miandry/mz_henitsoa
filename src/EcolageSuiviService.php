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
   * Billing months for the school year (September to June).
   */
  const ECOLAGE_BILLING_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

  const ECOLAGE_VACATION_MONTHS = [7, 8];

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
    $mois_terms = $this->getEcolageMoisTerms();
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
    $alerts = $this->buildAlerts($rows, $mois_terms, $annee_scolaire);

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
    $mois_terms = $this->getEcolageMoisTerms();
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
    $expected_months = $filter_mois_tid > 0 ? 1 : $this->getElapsedSchoolMonths(count($mois_terms), $annee_scolaire);

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

  protected function getElapsedSchoolMonths(int $total_months, ?string $annee_scolaire = NULL): int {
    $billing_calendar = $this->getSchoolCalendarMonths();
    $max = min($total_months, count($billing_calendar));

    if (!$annee_scolaire) {
      $now = (int) date('n');
      if (in_array($now, self::ECOLAGE_VACATION_MONTHS, TRUE)) {
        return $max;
      }
      $index = array_search($now, $billing_calendar, TRUE);
      if ($index === FALSE) {
        return $max;
      }
      return min($index + 1, $max);
    }

    $position = $this->getSchoolYearPosition($annee_scolaire);
    if ($position === 'not_started') {
      return 0;
    }
    if ($position === 'vacation' || $position === 'ended') {
      return $max;
    }

    $index = array_search((int) date('n'), $billing_calendar, TRUE);
    if ($index === FALSE) {
      return 0;
    }
    return min($index + 1, $max);
  }

  /**
   * Returns the previous billing month within the given school year.
   *
   * Examples for 2026-2027: September → none; October → SEPTEMBRE.
   * July after 2025-2026 ends → JUIN (last month of that year).
   */
  public function getPreviousSchoolMonth(array $mois_terms, ?string $annee_scolaire = NULL): ?array {
    $billing_terms = $this->filterEcolageMoisTerms($mois_terms);
    if (empty($billing_terms) || !$annee_scolaire) {
      return NULL;
    }

    $billing_calendar = $this->getSchoolCalendarMonths();
    $elapsed = $this->getElapsedSchoolMonths(count($billing_terms), $annee_scolaire);
    if ($elapsed < 2) {
      return NULL;
    }

    $position = $this->getSchoolYearPosition($annee_scolaire);
    if ($position === 'vacation' || $position === 'ended') {
      return $this->findMoisTermByCalendarMonth($billing_terms, 6);
    }

    $prev_calendar_month = $billing_calendar[$elapsed - 2];
    return $this->findMoisTermByCalendarMonth($billing_terms, $prev_calendar_month);
  }

  /**
   * Checks whether a month is marked paid on the inscription.
   */
  public function isInscriptionMonthPaid(Node $inscription, int $mois_tid): bool {
    if ($mois_tid <= 0) {
      return FALSE;
    }
    $paid_month_ids = array_map(
      fn($item) => (int) $item['target_id'],
      $inscription->get('field_ecolage_status')->getValue()
    );
    return in_array($mois_tid, $paid_month_ids, TRUE);
  }

  protected function getSchoolCalendarMonths(): array {
    return self::ECOLAGE_BILLING_MONTHS;
  }

  /**
   * Keeps only billing months (September to June).
   */
  public function filterEcolageMoisTerms(array $mois_terms): array {
    $billing_labels = array_map(
      fn(int $month) => $this->getCalendarMonthLabels()[$month],
      $this->getSchoolCalendarMonths()
    );
    return array_values(array_filter(
      $mois_terms,
      fn(array $mois) => in_array($mois['nom'], $billing_labels, TRUE)
    ));
  }

  /**
   * Returns billing months for écolage (September to June).
   */
  public function getEcolageMoisTerms(): array {
    return $this->filterEcolageMoisTerms($this->loadAllMoisTerms());
  }

  protected function findMoisTermByCalendarMonth(array $mois_terms, int $calendar_month): ?array {
    $label = $this->getCalendarMonthLabels()[$calendar_month] ?? NULL;
    if (!$label) {
      return NULL;
    }
    foreach ($mois_terms as $mois) {
      if ($mois['nom'] === $label) {
        return $mois;
      }
    }
    return NULL;
  }

  protected function getCalendarMonthLabels(): array {
    return [
      9 => 'SEPTEMBRE',
      10 => 'OCTOBRE',
      11 => 'NOVEMBRE',
      12 => 'DECEMBRE',
      1 => 'JANVIER',
      2 => 'FEVRIER',
      3 => 'MARS',
      4 => 'AVRIL',
      5 => 'MAI',
      6 => 'JUIN',
      7 => 'JUILLET',
      8 => 'AOUT',
    ];
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

  protected function buildAlerts(array $rows, array $mois_terms, ?string $annee_scolaire = NULL): array {
    $alerts = [];
    $seen = [];
    $previous_month = $this->getPreviousSchoolMonth($mois_terms, $annee_scolaire);
    $seuil = self::RETARD_MOIS_SEUIL;

    if ($previous_month) {
      foreach ($rows as $row) {
        $key = $row['inscription_id'];
        if (isset($seen[$key])) {
          continue;
        }
        $is_paid = FALSE;
        foreach ($row['mois_status'] as $mois) {
          if ((int) $mois['id'] === (int) $previous_month['id'] && !empty($mois['paid'])) {
            $is_paid = TRUE;
            break;
          }
        }
        if (!$is_paid) {
          $alerts[] = [
            'type' => 'retard_paiement',
            'label' => 'Retard écolage',
            'nom' => $row['eleve'],
            'classe' => $row['classe'],
            'detail' => $previous_month['nom'] . ' non payé',
            'url' => '/app/eleves-inscrits/' . $row['inscription_id'],
            'source' => 'live',
          ];
          $seen[$key] = TRUE;
        }
        if (count($alerts) >= 8) {
          break;
        }
      }
    }

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

  protected function getCurrentSchoolYearStart(): int {
    $month = (int) date('n');
    $year = (int) date('Y');
    return $month >= 9 ? $year : $year - 1;
  }

  protected function findSchoolYearByStart(array $years, int $start_year): ?string {
    foreach ($years as $year) {
      if ($this->parseSchoolYearStart($year) === $start_year) {
        return $year;
      }
    }
    return NULL;
  }

  protected function parseSchoolYearStart(string $year): ?int {
    if (preg_match('/(\d{4})\s*-\s*(\d{4})/', $year, $matches)) {
      return (int) $matches[1];
    }
    return NULL;
  }

  protected function parseSchoolYearEnd(string $year): ?int {
    if (preg_match('/(\d{4})\s*-\s*(\d{4})/', $year, $matches)) {
      return (int) $matches[2];
    }
    return NULL;
  }

  /**
   * Position of today relative to a school year label.
   *
   * @return string
   *   not_started | active | vacation | ended
   */
  protected function getSchoolYearPosition(?string $annee_scolaire): string {
    if (!$annee_scolaire) {
      return 'active';
    }

    $start_year = $this->parseSchoolYearStart($annee_scolaire);
    $end_year = $this->parseSchoolYearEnd($annee_scolaire);
    if ($start_year === NULL || $end_year === NULL) {
      return 'active';
    }

    $year = (int) date('Y');
    $month = (int) date('n');

    if ($year < $start_year || ($year === $start_year && $month < 9)) {
      return 'not_started';
    }

    if ($year === $end_year && in_array($month, self::ECOLAGE_VACATION_MONTHS, TRUE)) {
      return 'vacation';
    }

    if ($year > $end_year || ($year === $end_year && $month >= 9)) {
      return 'ended';
    }

    return 'active';
  }

  protected function isFutureSchoolYear(?string $annee_scolaire): bool {
    return $this->getSchoolYearPosition($annee_scolaire) === 'not_started';
  }

  protected function schoolYearHasInscriptions(string $year): bool {
    $count = \Drupal::entityQuery('node')
      ->condition('type', 'inscription')
      ->condition('field_annee_scolaire', $year)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    return $count > 0;
  }

  protected function getSchoolYearOptions(): array {
    $field = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'inscription')['field_annee_scolaire'];
    $years = array_keys($field->getSettings()['allowed_values']);
    rsort($years);
    return $years;
  }

  protected function getMoisTerms(): array {
    return $this->getEcolageMoisTerms();
  }

  protected function loadAllMoisTerms(): array {
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
