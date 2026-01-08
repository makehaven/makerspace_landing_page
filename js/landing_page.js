/**
 * @file
 * Landing page behaviors.
 */

(function (Drupal, drupalSettings) {
  Drupal.behaviors.makerspaceLandingPage = {
    attach: function (context, settings) {
      // Ensure we have settings
      if (!settings.makerspace_landing_page) {
        return;
      }

      const { coupon, tracking_code } = settings.makerspace_landing_page;

      // Apply Coupon
      if (coupon) {
        const chargebeeLinks = context.querySelectorAll('a[href*="makehaven.chargebee.com"]');
        chargebeeLinks.forEach(anchor => {
           try {
            const url = new URL(anchor.href);
            if (!url.searchParams.has('subscription[coupon]')) {
              url.searchParams.set('subscription[coupon]', coupon);
              anchor.href = url.toString();
            }
          } catch (e) {
            // Ignore
          }
        });
      }

      // Apply Tracking Code
      if (tracking_code) {
        // 1. Update links that already have utm_campaign
        const linksWithCampaign = context.querySelectorAll('a[href*="utm_campaign="]');
        linksWithCampaign.forEach(anchor => {
            updateUrl(anchor, tracking_code);
        });

        // 2. Add to specific targets if missing
        const specificTargets = context.querySelectorAll(
            'a[href*="/tour"]:not([href*="utm_campaign="]), a[href*="makehaven.chargebee.com"]:not([href*="utm_campaign="])'
        );
        specificTargets.forEach(anchor => {
            updateUrl(anchor, tracking_code);
        });
      }
    }
  };

  function updateUrl(anchor, campaign) {
      try {
        const url = new URL(anchor.href, window.location.origin);
        
        if (!url.searchParams.has('utm_source')) {
          url.searchParams.set('utm_source', 'landing_page');
        }
        if (!url.searchParams.has('utm_medium')) {
          url.searchParams.set('utm_medium', 'website');
        }
        
        url.searchParams.set('utm_campaign', campaign);
        
        anchor.href = url.toString();
      } catch (e) {
        // Ignore
      }
  }

})(Drupal, drupalSettings);
