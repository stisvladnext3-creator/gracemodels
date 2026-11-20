const choiceName = 'grace_cookie_choice';
const storageKeys = ['forma-language', 'forma-shortlist'];
let sessionChoice = null;

export function cookieChoice() {
  if (sessionChoice) return sessionChoice;
  const value = document.cookie.split('; ').find(part => part.startsWith(choiceName + '='))?.split('=')[1];
  return ['necessary', 'functional'].includes(value) ? value : null;
}

export function readPreference(key, fallback = null) {
  if (cookieChoice() !== 'functional' || !storageKeys.includes(key)) return fallback;
  try { return localStorage.getItem(key) ?? fallback; } catch { return fallback; }
}

export function writePreference(key, value) {
  if (cookieChoice() !== 'functional' || !storageKeys.includes(key)) return;
  try { localStorage.setItem(key, value); } catch { /* Browser storage can be disabled. */ }
}

function saveChoice(choice) {
  sessionChoice = choice;
  document.cookie = `${choiceName}=${choice}; Max-Age=31536000; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  if (choice === 'necessary') {
    for (const key of storageKeys) {
      try { localStorage.removeItem(key); } catch { /* The user's choice still applies in memory. */ }
    }
  }
  document.querySelector('#cookie-notice')?.remove();
  window.dispatchEvent(new CustomEvent('grace-cookie-preferences', {detail: {choice}}));
}

export function openCookieSettings() {
  document.querySelector('#cookie-notice')?.remove();
  const english = document.documentElement.lang === 'en';
  const notice = document.createElement('section');
  notice.id = 'cookie-notice';
  notice.className = 'cookie-notice';
  notice.setAttribute('role', 'region');
  notice.setAttribute('aria-labelledby', 'cookie-title');
  notice.innerHTML = `<div class="cookie-copy"><h2 id="cookie-title">${english ? 'Your cookie preferences' : 'Файлы cookie'}</h2><p>${english ? 'We use essential cookies to keep the website and administrator sign-in working. With your permission, we also remember your language and shortlisted models on this device.' : 'Мы используем необходимые cookie для работы сайта и входа. С вашего согласия также сохраняем на устройстве выбранный язык и избранные модели.'} <a href="/cookies" target="_blank" rel="noopener">${english ? 'Learn more' : 'Подробнее'}</a></p></div><div class="cookie-actions"><button type="button" data-cookie-choice="functional">${english ? 'Accept' : 'Принять'}</button><button type="button" data-cookie-choice="necessary">${english ? 'Essential only' : 'Только необходимые'}</button></div>`;
  notice.addEventListener('click', event => {
    const button = event.target.closest('[data-cookie-choice]');
    if (button) saveChoice(button.dataset.cookieChoice);
  });
  document.body.append(notice);
}

document.addEventListener('click', event => {
  if (event.target.closest('[data-cookie-settings]')) openCookieSettings();
});
if (!cookieChoice()) openCookieSettings();
