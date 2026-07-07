<?php

namespace Drupal\mz_henitsoa\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Forces the henitsoaapp Vue.js theme on the /app route and its sub-paths.
 */
class AppThemeNegotiator implements ThemeNegotiatorInterface {

  const ROUTES = ['mz_henitsoa.app', 'mz_henitsoa.app_subpath', 'mz_henitsoa.app_subpath_detail'];

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    return in_array($route_match->getRouteName(), self::ROUTES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    return 'henitsoaapp';
  }

}
