(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.grokPricingConfirmation = {
    attach(context) {
      once('grok-pricing-confirm', '[data-grok-confirm]', context).forEach((button) => {
        button.addEventListener('click', (event) => {
          if (!window.confirm(button.dataset.grokConfirm)) {
            event.preventDefault();
            event.stopImmediatePropagation();
          }
        }, true);
      });
    },
  };
})(Drupal, once);
