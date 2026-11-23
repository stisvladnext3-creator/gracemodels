const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const uid=prefix=>prefix+'_'+crypto.randomUUID().replaceAll('-','').slice(0,16);
const types={text:'Текст',textarea:'Большой текст',email:'Электронная почта',tel:'Телефон',url:'Ссылка',number:'Число',date:'Дата',select:'Список',checkbox:'Отметка',photos:'Фотографии',model:'Выбор модели'};
const itemTypes={link:'Ссылка',text:'Текст',phone:'Телефон',email:'Электронная почта',address:'Адрес'};
const input=(label,value,prop,extra='')=>`<label class="builder-input">${esc(label)}<input data-prop="${prop}" value="${esc(value)}" ${extra}></label>`;
const area=(label,value,prop,rows=3)=>`<label class="builder-input">${esc(label)}<textarea data-prop="${prop}" rows="${rows}" maxlength="5000">${esc(value)}</textarea></label>`;
const select=(label,value,prop,options)=>`<label class="builder-input">${esc(label)}<select data-prop="${prop}">${Object.entries(options).map(([key,title])=>`<option value="${esc(key)}" ${String(value)===key?'selected':''}>${esc(title)}</option>`).join('')}</select></label>`;
const check=(label,value,prop,disabled=false)=>`<label class="builder-check"><input type="checkbox" data-prop="${prop}" ${value?'checked':''} ${disabled?'disabled':''}>${esc(label)}</label>`;
const read=element=>Object.fromEntries([...element.querySelectorAll(':scope > [data-prop], :scope > label > [data-prop]')].map(control=>[control.dataset.prop,control.type==='checkbox'?control.checked:control.value]));
const orderButtons=(scope,index,total)=>`<div class="builder-order"><button type="button" class="btn secondary small" data-do="${scope}-up" data-index="${index}" ${index===0?'disabled':''}>Выше</button><button type="button" class="btn secondary small" data-do="${scope}-down" data-index="${index}" ${index===total-1?'disabled':''}>Ниже</button></div>`;
function move(list,index,direction){const target=index+direction;if(target<0||target>=list.length)return;[list[index],list[target]]=[list[target],list[index]];}

export function mountFormsEditor(root,context){
  const drafts=structuredClone(context.data.forms);let pending=0;
  const capture=()=>root.querySelectorAll('[data-form-kind]').forEach(form=>{
    const config=drafts[form.dataset.formKind];Object.assign(config,read(form.querySelector('.builder-form-meta')));
    config.fields=[...form.querySelectorAll('[data-field-key]')].map(card=>{
      const field={...config.fields.find(item=>item.key===card.dataset.fieldKey),...read(card.querySelector('.builder-field-controls'))};
      if(field.key.startsWith('custom_')&&field.type==='select')field.options=(card.querySelector('[data-options]')?.value??field.options.map(option=>option[1]).join('\n')).split('\n').map(x=>x.trim()).filter(Boolean).map(value=>[value,value]);
      return field;
    });
  });
  function draw(){
    root.innerHTML=Object.entries(drafts).map(([kind,config])=>`<form novalidate class="panel builder-form" data-form-kind="${kind}"><h2>${kind==='application'?'Стать моделью':'Бронирование моделей'}</h2><div class="builder-form-meta builder-grid">${input('Надпись над заголовком',config.eyebrow,'eyebrow','maxlength="200"')}${input('Текст кнопки отправки',config.submit_label,'submit_label','required maxlength="100"')}${area('Заголовок формы',config.title,'title',2)}${area('Описание формы',config.description,'description',4)}${input('Заголовок после отправки',config.success_title,'success_title','required maxlength="300"')}${area('Текст после отправки',config.success_text,'success_text')}${kind==='application'?area('Текст обязательной отметки для кандидатов до 18 лет',config.guardian_label,'guardian_label'):''}${input('Адрес фотографии формы',config.image,'image','maxlength="1200"')}</div><div class="builder-image-row">${config.image?`<img src="${esc(config.image)}" alt="Фото формы">`:''}<label class="builder-input">Заменить фотографию<input type="file" data-form-photo accept="image/jpeg,image/png,image/webp"></label></div><h3>Поля формы</h3><p class="builder-help">Меняйте названия, подсказки, обязательность, видимость и порядок. Имя, телефон, почта и возраст кандидата остаются обязательными. Согласие на обработку данных выводится всегда.</p><div class="builder-fields">${config.fields.map((field,index)=>fieldCard(kind,field,index,config.fields.length)).join('')}</div><button class="btn secondary" type="button" data-do="add-field">Добавить поле</button><div class="builder-status" role="status"></div><div class="builder-error" role="alert"></div><div class="builder-save"><button class="btn" type="submit">Сохранить и опубликовать форму</button><a class="btn secondary" href="${kind==='application'?'/become-a-model':'/booking'}" target="_blank" rel="noopener">Посмотреть форму</a></div></form>`).join('');
  }
  function fieldCard(kind,field,index,total){
    const custom=field.key.startsWith('custom_'),locked=['name','email','phone',...(kind==='application'?['age']:[])].includes(field.key);
    return `<details class="builder-card" data-field-key="${esc(field.key)}"><summary>${esc(field.label||'Новое поле')} <span>${esc(types[field.type]||field.type)}${field.required?' · обязательное':''}${field.visible?'':' · скрыто'}</span></summary><div class="builder-field-controls builder-grid">${input('Название поля',field.label,'label','required maxlength="200"')}${custom?select('Тип поля',field.type,'type',Object.fromEntries(Object.entries(types).filter(([key])=>!['photos','model'].includes(key)))):''}${input('Подсказка внутри поля',field.placeholder,'placeholder','maxlength="300"')}${area('Пояснение под полем',field.help,'help')}${check('Показывать поле',field.visible,'visible',locked)}${check('Обязательное поле',field.required,'required',locked)}${check('На всю ширину формы',field.full,'full')}${custom&&field.type==='select'?`<label class="builder-input">Варианты списка, каждый с новой строки<textarea rows="5" data-options>${esc(field.options.map(option=>option[1]).join('\n'))}</textarea></label>`:''}</div><div class="builder-card-actions">${orderButtons('field',index,total)}${custom?`<button class="btn danger small" type="button" data-do="delete-field" data-index="${index}">Удалить поле</button>`:''}</div></details>`;
  }
  root.addEventListener('click',event=>{
    const button=event.target.closest('[data-do]');if(!button)return;
    capture();const form=button.closest('[data-form-kind]');if(!form)return;const fields=drafts[form.dataset.formKind].fields,index=Number(button.dataset.index);
    if(button.dataset.do==='add-field')fields.push({key:uid('custom'),label:'Новое поле',type:'text',required:false,visible:true,full:false,placeholder:'',help:'',options:[]});
    if(button.dataset.do==='delete-field')fields.splice(index,1);
    if(button.dataset.do==='field-up')move(fields,index,-1);
    if(button.dataset.do==='field-down')move(fields,index,1);
    draw();
  });
  root.addEventListener('change',async event=>{
    if(event.target.matches('[data-prop="type"]')){capture();const card=event.target.closest('[data-field-key]'),kind=event.target.closest('[data-form-kind]').dataset.formKind,field=drafts[kind].fields.find(x=>x.key===card.dataset.fieldKey);if(field.type==='select'&&!field.options.length)field.options=[['Первый вариант','Первый вариант']];draw();return;}
    if(!event.target.matches('[data-form-photo]')||!event.target.files.length)return;
    capture();const form=event.target.closest('[data-form-kind]'),kind=form.dataset.formKind;pending++;
    form.querySelector('.builder-status').textContent='Загрузка фотографии…';
    try{drafts[kind].image=await context.upload(event.target.files[0]);captureImageSafe(form,drafts[kind].image);draw();context.toast('Фото загружено. Сохраните форму.');}catch(error){form.querySelector('.builder-error').textContent=error.message;}finally{pending--;}
  });
  function captureImageSafe(form,url){capture();drafts[form.dataset.formKind].image=url;}
  root.addEventListener('submit',async event=>{
    const form=event.target.closest('[data-form-kind]');if(!form)return;event.preventDefault();
    const error=form.querySelector('.builder-error'),button=form.querySelector('button[type=submit]');error.textContent='';if(pending){error.textContent='Дождитесь загрузки фотографий.';return;}
    if(!form.checkValidity()){form.querySelector(':invalid')?.closest('details')?.setAttribute('open','');form.reportValidity();return;}capture();const kind=form.dataset.formKind;button.disabled=true;
    try{const saved=await context.api('forms/'+kind,{method:'PUT',body:{config:drafts[kind],expected_version:drafts[kind].version}});capture();drafts[kind]=saved;context.onSaved(await context.api('data'));draw();context.toast('Форма опубликована');}catch(failure){error.textContent=failure.message;button.disabled=false;}
  });
  draw();
}

const sources={'':'Ввести значение вручную',email:'Почта из настроек',phone:'Телефон из настроек',city:'Город из настроек',telegram:'Телеграм из настроек',instagram:'Инстаграм из настроек',vk:'ВКонтакте из настроек'};
function itemCard(item,index,total,navigation=false){return `<div class="builder-item" data-item-id="${esc(item.id)}"><div class="builder-item-controls builder-grid">${select('Тип',item.type,'type',itemTypes)}${input('Подпись ссылки или название',item.label,'label','maxlength="250"')}${select('Значение',item.source,'source',sources)}${input('Текст, адрес, телефон или ссылка',item.value,'value','maxlength="1500"')}${navigation?select('Расположение',item.placement,'placement',{main:'Основное меню',actions:'Справа в шапке'}):''}${check('Показывать',item.enabled,'enabled')}</div><div class="builder-card-actions">${orderButtons('item',index,total)}<button type="button" class="btn danger small" data-do="delete-item" data-index="${index}">Удалить элемент</button></div></div>`;}
export function mountLayoutEditor(root,context){
  const drafts=structuredClone(context.data.layout);let version=drafts.version;
  function capture(){
    const nav=root.querySelector('[data-layout-section="navigation"]');if(nav)drafts.navigation=[...nav.querySelectorAll('[data-item-id]')].map(card=>({...drafts.navigation.find(x=>x.id===card.dataset.itemId),...read(card.querySelector('.builder-item-controls'))}));
    const footer=root.querySelector('[data-layout-section="footer"]');if(footer)drafts.footer=[...footer.querySelectorAll('[data-block-id]')].map(card=>{
      const block={...drafts.footer.find(x=>x.id===card.dataset.blockId),...read(card.querySelector('.builder-block-controls'))};
      block.width=Number(block.width);block.items=[...card.querySelectorAll('[data-item-id]')].map(item=>({...block.items.find(x=>x.id===item.dataset.itemId),...read(item.querySelector('.builder-item-controls'))}));return block;
    });
  }
  function draw(){root.innerHTML=`<form novalidate class="panel builder-form" data-layout-section="navigation"><h2>Навигационная панель</h2><p class="builder-help">Добавляйте пункты меню, контакты и текст. «Выше» и «Ниже» меняют порядок в выбранной части шапки. Телефон, почту и город можно брать из настроек агентства.</p>${drafts.navigation.map((item,index)=>itemCard(item,index,drafts.navigation.length,true)).join('')}<button class="btn secondary" type="button" data-do="add-nav">Добавить элемент меню</button>${saveBar('Сохранить навигацию')}</form><form novalidate class="panel builder-form" data-layout-section="footer"><h2>Футер сайта</h2><p class="builder-help">Меняйте порядок блоков и элементов, ширину и выравнивание. Для телефона выберите тип «Телефон», для адреса — «Адрес». Ссылки на правовые документы и настройки cookie выводятся под блоками.</p>${drafts.footer.map((block,index)=>`<section class="builder-card builder-footer-block" data-block-id="${esc(block.id)}"><div class="builder-block-controls builder-grid">${input('Заголовок блока',block.title,'title','maxlength="250"')}${select('Содержимое',block.kind,'kind',{content:'Элементы',brand:'Логотип, слоган и элементы'})}${select('Выравнивание',block.align,'align',{left:'Слева',center:'По центру',right:'Справа'})}${select('Ширина',block.width,'width',{'1':'Одна колонка','2':'Две колонки','3':'Три колонки','4':'Вся ширина'})}${check('Показывать блок',block.enabled,'enabled')}</div><div class="builder-card-actions">${orderButtons('block',index,drafts.footer.length)}<button class="btn danger small" type="button" data-do="delete-block" data-index="${index}">Удалить блок</button></div><div class="builder-block-items">${block.items.map((item,itemIndex)=>itemCard(item,itemIndex,block.items.length)).join('')}</div><button class="btn secondary small" type="button" data-do="add-footer-item">Добавить элемент в блок</button></section>`).join('')}<button class="btn secondary" type="button" data-do="add-block">Добавить блок футера</button>${saveBar('Сохранить футер')}</form>`;}
  function saveBar(label){return `<div class="builder-error" role="alert"></div><div class="builder-save"><button class="btn" type="submit">${label}</button><a class="btn secondary" href="/" target="_blank" rel="noopener">Открыть сайт</a></div>`;}
  const blankItem=()=>({id:uid('item'),type:'text',label:'',value:'',source:'',enabled:true});
  root.addEventListener('click',event=>{
    const button=event.target.closest('[data-do]');if(!button)return;capture();
    const section=button.closest('[data-layout-section]').dataset.layoutSection,action=button.dataset.do,index=Number(button.dataset.index),blockCard=button.closest('[data-block-id]'),block=blockCard?drafts.footer.find(x=>x.id===blockCard.dataset.blockId):null;
    const list=section==='navigation'?drafts.navigation:block?.items;
    if(action==='add-nav')drafts.navigation.push({...blankItem(),type:'link',value:'/contacts',label:'Новый пункт',placement:'main'});
    if(action==='add-block')drafts.footer.push({id:uid('footer'),title:'Новый блок',kind:'content',align:'left',width:1,enabled:true,items:[]});
    if(action==='add-footer-item')block.items.push(blankItem());
    if(action==='delete-item')list.splice(index,1);
    if(action==='item-up')move(list,index,-1);if(action==='item-down')move(list,index,1);
    if(action==='delete-block')drafts.footer.splice(index,1);
    if(action==='block-up')move(drafts.footer,index,-1);if(action==='block-down')move(drafts.footer,index,1);
    draw();
  });
  root.addEventListener('submit',async event=>{
    const form=event.target.closest('[data-layout-section]');if(!form)return;event.preventDefault();if(!form.checkValidity()){form.querySelector(':invalid')?.closest('details')?.setAttribute('open','');form.reportValidity();return;}
    capture();const section=form.dataset.layoutSection,error=form.querySelector('.builder-error'),button=form.querySelector('button[type=submit]');error.textContent='';button.disabled=true;
    try{const saved=await context.api('layout',{method:'PUT',body:{[section]:drafts[section],expected_version:version}});capture();drafts[section]=saved[section];version=saved.version;context.onSaved(await context.api('data'));draw();context.toast(section==='footer'?'Футер опубликован':'Навигация опубликована');}catch(failure){error.textContent=failure.message;button.disabled=false;}
  });
  draw();
}

export function mountContactsEditor(root,context){
  let draft=structuredClone(context.data.contacts);
  const capture=()=>{draft.items=[...root.querySelectorAll('[data-contact-id]')].map(card=>({...draft.items.find(person=>person.id===card.dataset.contactId),...read(card.querySelector('.builder-contact-controls'))}));};
  const draw=()=>{root.innerHTML=`<form novalidate class="panel builder-form"><h2>Telegram сотрудников</h2><p class="builder-help">Укажите ник с @ или ссылку https://t.me/username. Добавьте подпись: букинг, кастинги, скаутинг или другое направление. Контакты отображаются на странице «Контакты» в указанном порядке.</p>${draft.items.map((person,index)=>`<section class="builder-card" data-contact-id="${esc(person.id)}"><div class="builder-contact-controls builder-grid">${input('Имя сотрудника',person.name,'name','maxlength="200"')}${input('За какую работу отвечает',person.role,'role','required maxlength="400"')}${input('Telegram',person.telegram,'telegram','required maxlength="300"')}${check('Показывать на сайте',person.enabled,'enabled')}</div><div class="builder-card-actions">${orderButtons('contact',index,draft.items.length)}<button class="btn danger small" type="button" data-do="delete-contact" data-index="${index}">Удалить контакт</button></div></section>`).join('')}<button class="btn secondary" type="button" data-do="add-contact">Добавить Telegram</button><div class="builder-error" role="alert"></div><div class="builder-save"><button class="btn" type="submit">Сохранить контакты</button><a class="btn secondary" href="/contacts" target="_blank" rel="noopener">Открыть страницу контактов</a></div></form>`;};
  root.addEventListener('click',event=>{
    const button=event.target.closest('[data-do]');if(!button)return;capture();const index=Number(button.dataset.index);
    if(button.dataset.do==='add-contact')draft.items.push({id:uid('person'),name:'',role:'',telegram:'',enabled:true});
    if(button.dataset.do==='delete-contact')draft.items.splice(index,1);
    if(button.dataset.do==='contact-up')move(draft.items,index,-1);if(button.dataset.do==='contact-down')move(draft.items,index,1);draw();
  });
  root.addEventListener('submit',async event=>{
    event.preventDefault();const form=event.target,error=form.querySelector('.builder-error'),button=form.querySelector('button[type=submit]');error.textContent='';if(!form.reportValidity())return;capture();button.disabled=true;
    try{draft=await context.api('contacts',{method:'PUT',body:{items:draft.items,expected_version:draft.version}});context.onSaved(await context.api('data'));draw();context.toast('Контакты опубликованы');}catch(failure){error.textContent=failure.message;button.disabled=false;}
  });
  draw();
}
