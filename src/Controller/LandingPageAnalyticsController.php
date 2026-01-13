<?php

namespace Drupal\makerspace_landing_page\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\node\NodeInterface;

/**
 * Controller for Landing Page Analytics redirection.
 */
class LandingPageAnalyticsController extends ControllerBase {

  /**
   * Redirects to the Google Analytics dashboard.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The landing page node.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   */
  public function gotoAnalytics(NodeInterface $node) {
    $config = $this->config('makerspace_landing_page.settings');
    $property_id = $config->get('google_analytics_property');
    
    // Default fallback.
    $analytics_url = 'https://analytics.google.com/analytics/web/';

    if ($property_id) {
        $node_path = $node->toUrl()->toString();
        $encoded_path = urlencode(urlencode($node_path));
        // User preferred format.
        $analytics_url = "https://analytics.google.com/analytics/web/#/p{$property_id}/reports/explorer?params=_u..nav%3Dmaui%26_r.explorerCard..filterTerm%3D{$encoded_path}&r=all-pages-and-screens";
    }

    return new TrustedRedirectResponse($analytics_url);
  }

}