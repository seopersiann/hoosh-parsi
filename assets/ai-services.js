'use strict';

// The page remains fully readable without JavaScript or motion support.
(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (!('IntersectionObserver' in window)) return;

  const elements = document.querySelectorAll('.ai-page [data-ai-reveal]');
  if (!elements.length) return;

  const observer = new IntersectionObserver(entries => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      entry.target.classList.add('ai-revealed');
      observer.unobserve(entry.target);
    }
  }, {
    threshold: 0.08,
    rootMargin: '0px 0px -24px 0px'
  });

  elements.forEach(element => observer.observe(element));
})();
