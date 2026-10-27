'use strict';
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#main-nav');
function closeMenu(returnFocus = false) {
  if (!navigation || !menuButton) return;
  navigation.classList.remove('is-open');
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.setAttribute('aria-label', 'باز کردن فهرست');
  if (returnFocus) menuButton.focus();
}
if (menuButton && navigation) {
  menuButton.addEventListener('click', () => {
    const opening = menuButton.getAttribute('aria-expanded') !== 'true';
    navigation.classList.toggle('is-open', opening);
    menuButton.setAttribute('aria-expanded', String(opening));
    menuButton.setAttribute('aria-label', opening ? 'بستن فهرست' : 'باز کردن فهرست');
  });
  navigation.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && navigation.classList.contains('is-open')) closeMenu(true); });
  document.addEventListener('click', event => { if (!event.target.closest('.site-header')) closeMenu(); });
  navigation.addEventListener('focusout', event => { if (!navigation.contains(event.relatedTarget) && event.relatedTarget !== menuButton) closeMenu(); });
  window.matchMedia('(min-width: 1001px)').addEventListener('change', () => closeMenu());
  const navLinks = [...navigation.querySelectorAll('a')];
  const normalizePath = path => path.endsWith('/') ? `${path}index.html` : path;
  const localLinks = navLinks.filter(link => link.hash && normalizePath(link.pathname) === normalizePath(location.pathname));
  const sections = localLinks.map(link => document.getElementById(decodeURIComponent(link.hash.slice(1)))).filter(Boolean);
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        navLinks.forEach(link => {
          const active = localLinks.includes(link) && link.hash === `#${entry.target.id}`;
          link.classList.toggle('active', active);
          if (active) link.setAttribute('aria-current', 'location');
          else link.removeAttribute('aria-current');
        });
      }
    }, { rootMargin: '-10% 0px -60% 0px', threshold: 0 });
    sections.forEach(section => observer.observe(section));
  }
}
// Filters enhance fully visible HTML cards; all concepts remain available without JS.
const filters = document.querySelector('.portfolio-filters');
if (filters) {
  filters.hidden = false;
  const cards = [...document.querySelectorAll('.portfolio-card')];
  filters.addEventListener('click', event => {
    const button = event.target.closest('button[data-filter]');
    if (!button) return;
    filters.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    cards.forEach(card => { card.hidden = button.dataset.filter !== 'all' && card.dataset.category !== button.dataset.filter; });
    const count = cards.filter(card => !card.hidden).length.toLocaleString('fa-IR');
    document.querySelector('.filter-status').textContent = `${count} نمونهٔ طراحی نمایش داده می‌شود.`;
  });
}
// A plan link only prefills an on-device brief. No request or purchase is submitted.
const plans = {
  starter: { name: 'فروشگاه اقتصادی', price: '۱۸ میلیون تومان', service: 'فروشگاه اینترنتی' },
  corporate: { name: 'فروشگاه حرفه‌ای', price: '۳۰ میلیون تومان', service: 'فروشگاه اینترنتی' },
  store: { name: 'فروشگاه اختصاصی', price: '۵۰ میلیون تومان', service: 'فروشگاه اینترنتی' }
};
const planKey = new URLSearchParams(location.search).get('plan');
const chosenPlan = Object.prototype.hasOwnProperty.call(plans, planKey) ? plans[planKey] : null;
const form = document.querySelector('#project-form');
if (form) {
  form.querySelector('button[type="submit"]').disabled = false;
  const brief = form.querySelector('#brief');
  const selectedLabel = document.querySelector('#selected-plan');
  if (chosenPlan) {
    form.querySelector('#service').value = chosenPlan.service;
    if (selectedLabel) { selectedLabel.hidden = false; selectedLabel.textContent = `پلن انتخابی: ${chosenPlan.name} — ${chosenPlan.price}`; }
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!brief.value.trim()) { brief.setCustomValidity('لطفاً چند کلمه درباره پروژه بنویسید.'); brief.reportValidity(); return; }
    const name = form.querySelector('#name').value.trim() || 'مشخص نشده';
    const service = form.querySelector('#service').value;
    const planLine = chosenPlan ? `پلن اولیه: ${chosenPlan.name} — ${chosenPlan.price}\n` : '';
    const content = `شرح اولیه پروژه — هوشمند پارسی\n\nنام یا کسب‌وکار: ${name}\nنوع پروژه: ${service}\n${planLine}\nشرح نیاز:\n${brief.value.trim()}\n\nاین فایل روی دستگاه شما آماده شده و اطلاعاتی برای هوشمند پارسی ارسال نشده است. انتخاب پلن به معنی ثبت سفارش یا توافق قطعی نیست.`;
    const url = URL.createObjectURL(new Blob(['\uFEFF' + content], { type: 'text/plain;charset=utf-8' }));
    const download = document.createElement('a');
    download.href = url; download.download = 'hooshmand-parsi-project.txt';
    document.body.append(download); download.click(); download.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    document.querySelector('#form-status').textContent = 'فایل شرح پروژه برای دانلود آماده شد؛ هیچ اطلاعاتی ارسال نشده است.';
  });
  brief.addEventListener('input', event => event.target.setCustomValidity(''));
}

// Accessible enhancement over native video controls; never autoplay or fake playback.
document.querySelectorAll('[data-video-player]').forEach(wrapper => {
  const video = wrapper.querySelector('video');
  const button = wrapper.querySelector('.video-play-button');
  const host = wrapper.closest('.hero-video, .watch-page');
  const status = host?.querySelector('.video-status');
  if (!video || !button) return;
  button.hidden = false;
  const sync = () => {
    button.hidden = !video.paused && !video.ended;
    button.setAttribute('aria-label', video.ended ? 'پخش دوبارهٔ ویدیو' : 'پخش ویدیوی آموزش طراحی سایت مدرن');
  };
  button.addEventListener('click', async () => {
    try {
      if (video.ended) video.currentTime = 0;
      if (status) status.textContent = '';
      await video.play();
    } catch {
      if (status) status.textContent = 'پخش شروع نشد. اتصال اینترنت را بررسی کنید یا دوباره دکمهٔ پخش را بزنید.';
      sync();
    }
  });
  video.addEventListener('play', sync);
  video.addEventListener('pause', sync);
  video.addEventListener('ended', sync);
  video.addEventListener('error', () => {
    button.hidden = true;
    if (status) {
      status.textContent = 'ویدیو بارگذاری نشد. می‌توانید ';
      const link = document.createElement('a');
      link.href = video.querySelector('source').src;
      link.textContent = 'فایل ویدیو را مستقیم باز کنید';
      status.append(link, ' یا بعداً دوباره تلاش کنید.');
    }
  });
});
