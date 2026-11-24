import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { initialLanguage,localizedChrome,pageTitle,pageKey,languageHref } from '../public/site-locale.js';
import { mountLayoutEditor } from '../public/site-editor.js';

test('language switch selects both menu and footer for the requested language',()=>{
  const ru={navigation:[{html:'Основной борд'}],footer:'Реквизиты'},en={navigation:[{html:'Main board'}],footer:'Company details'};
  const data={chrome:{...ru,locales:{ru,en}},page_titles:{ru:{main:'Основной борд'},en:{main:'Main board'}}};
  assert.equal(localizedChrome(data,'en'),en);assert.equal(localizedChrome(data,'ru'),ru);
  assert.equal(pageTitle(data,'main','ru'),'Основной борд');assert.equal(pageTitle(data,'main','en'),'Main board');
  assert.equal(localizedChrome({chrome:ru},'en'),ru);
});
test('category and saved page identity is independent of the language query',()=>{
  for(const lang of ['ru','en']){
    assert.equal(pageKey('/models?lang='+lang),'main');
    assert.equal(pageKey('/models?category=new&lang='+lang),'new');
    assert.equal(pageKey('/models?saved=1&lang='+lang),'shortlist');
  }
  assert.equal(pageKey('/about?lang=en'),'about');assert.equal(pageKey('/models/anna'),null);
});
test('English internal links preserve parameters and fragments without changing external links',()=>{
  assert.equal(languageHref('/models?category=new#list','en'),'/models?category=new&lang=en#list');
  assert.equal(languageHref('/privacy','en'),'/privacy?lang=en');
  assert.equal(languageHref('/models?lang=ru&category=main','en'),'/models?lang=en&category=main');
  for(const href of ['https://example.test','//example.test','mailto:info@grace-models.ru'])assert.equal(languageHref(href,'en'),href);
  assert.equal(languageHref('/models','ru'),'/models');
});
test('an explicit language URL takes precedence over a stored language',()=>{
  assert.equal(initialLanguage('?lang=ru','en'),'ru');assert.equal(initialLanguage('?lang=en','ru'),'en');
  assert.equal(initialLanguage('','en'),'en');assert.equal(initialLanguage('?lang=unknown','bad'),'ru');
});
test('layout editor exposes separate RU and EN fields and a page title editor',()=>{
  const layout=JSON.parse(fs.readFileSync(new URL('../app/site-defaults.json',import.meta.url),'utf8')).layout;
  const root={innerHTML:'',addEventListener(){}};
  mountLayoutEditor(root,{data:{layout,page_titles:{ru:{main:'Основной борд'},en:{main:'Main board'}}}});
  assert.match(root.innerHTML,/Подпись RU/);assert.match(root.innerHTML,/Подпись EN/);
  assert.match(root.innerHTML,/data-prop="title_en"/);assert.match(root.innerHTML,/data-prop="value_en"/);
  assert.match(root.innerHTML,/data-layout-section="pages"/);assert.match(root.innerHTML,/placeholder="Основной борд"/);
  assert.doesNotMatch(root.innerHTML,/>undefined</);
});
test('catalog renders the configured board name in the heading and category tab',()=>{
  const source=fs.readFileSync(new URL('../public/app.js',import.meta.url),'utf8');
  const body=source.slice(source.indexOf('function catalog()'),source.indexOf('function rangeFilter('));
  const data={models:[],page_titles:{ru:{main:'Основной борд',new:'Новые лица',shortlist:'Избранное'},en:{main:'Main board',new:'New faces',shortlist:'Shortlist'}}};
  for(const lang of ['ru','en']){
    const context={URLSearchParams,state:{data,lang,saved:[]},location:{search:'?category=main'},pageName:key=>pageTitle(data,key,lang),tr:(ru,en)=>lang==='en'?en:ru,esc:s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;'),eyebrow:s=>s,rangeFilter:()=>'',filterSelect:()=>'',bustCups:[],labels:{eyes:{},hair:{}},label:(type,key)=>key};
    const html=vm.runInNewContext(body+';catalog()',context);
    const title=lang==='en'?'Main board':'Основной борд';
    assert.ok(html.includes(`<span>${title}</span>`));assert.ok(html.includes(`data-category="main">${title}</button>`));
  }
});
