const defaults={main:['Модели','Models'],new:['Новые лица','New faces'],international:['Международные','International'],direct:['Привоз','Direct'],shortlist:['Избранное','Shortlist'],about:['О нас','About us'],contacts:['Контакты','Contacts'],news:['Новости','News'],application:['Стать моделью','Become a model'],booking:['Бронирование','Book a model']};

export function initialLanguage(search,persisted='ru'){
  const value=new URLSearchParams(search).get('lang');
  return value==='ru'||value==='en'?value:persisted==='en'?'en':'ru';
}
export function localizedChrome(data,lang){return data.chrome.locales?.[lang]||data.chrome;}
export function pageTitle(data,key,lang){return data.page_titles?.[lang]?.[key]||defaults[key]?.[lang==='en'?1:0]||'Grace Models';}
export function pageKey(href){
  const url=new URL(href,'https://grace-models.invalid');
  const path=url.pathname.replace(/\/$/,'')||'/';
  if(path==='/models'){
    if(url.searchParams.get('saved')==='1')return 'shortlist';
    const category=url.searchParams.get('category')||'main';return ['main','new','international','direct'].includes(category)?category:'main';
  }
  return {'/about':'about','/contacts':'contacts','/news':'news','/become-a-model':'application','/booking':'booking'}[path]||null;
}
export function languageHref(href,lang){
  if(lang!=='en'||!href.startsWith('/')||href.startsWith('//'))return href;
  const url=new URL(href,'https://grace-models.invalid');url.searchParams.set('lang','en');
  return url.pathname+url.search+url.hash;
}
