import { hairColors, eyeColors } from './model-options.js';

const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

export function renderFormField(field,models=[],selectedId='',lang='ru') {
  if(!field.visible)return '';
  const key=field.key,type=field.type,id='form-'+key,required=field.required?' required':'',star=field.required?' <span class="required-mark" aria-hidden="true">*</span>':'';
  const label=escape(field.label),help=field.help?`<small>${escape(field.help)}</small>`:'';
  let control='';
  if(type==='photos')return `<div class="field full"><label for="form-photos">${label}${star}</label><div class="photo-drop-zone" id="photo-drop-zone"><p>Перетащите фотографии сюда или выберите файлы.</p><input type="file" id="form-photos" name="photos[]" multiple accept="image/jpeg,image/png,image/webp"${required}></div>${help}<div id="file-summary" aria-live="polite"></div></div>`;
  if(type==='checkbox')return `<div class="field ${field.full?'full':''}"><label class="consent" for="${id}"><input id="${id}" type="checkbox" name="${key}" value="yes"${required}><span>${label}${star}</span></label>${help}</div>`;
  if(type==='textarea')control=`<textarea id="${id}" name="${key}" rows="5" maxlength="5000" placeholder="${escape(field.placeholder)}"${required}></textarea>`;
  else if(type==='select'||type==='model') {
    const colors={hair:hairColors,eyes:eyeColors}[key];
    const options=type==='model'?models.map(m=>[m.id,m.name]):field.options.map(([value,title])=>[value,colors?.[value]?.[lang==='en'?1:0]||title]);
    control=`<select id="${id}" name="${key}"${required}><option value="">${type==='model'?'Помогите с подбором':'Выберите'}</option>${options.map(([value,title])=>`<option value="${escape(value)}" ${type==='model'&&value===selectedId?'selected':''}>${escape(title)}</option>`).join('')}</select>`;
  } else {
    const limits=['min','max'].filter(limit=>field[limit]!==undefined).map(limit=>` ${limit}="${escape(field[limit])}"`).join('');
    const length={name:100,city:100,telegram:100,email:200,phone:60,company:200}[key]||1200;
    const autocomplete={name:'name',city:'address-level2',email:'email',phone:'tel'}[key]||'off';
    control=`<input id="${id}" name="${key}" type="${escape(type)}" maxlength="${length}" autocomplete="${autocomplete}" placeholder="${escape(field.placeholder)}"${limits}${type==='number'?` step="${key.startsWith('custom_')?'any':'1'}"`:''}${required}>`;
  }
  return `<div class="field ${field.full?'full':''}"><label for="${id}">${label}${star}</label>${control}${help}</div>`;
}

export function needsGuardian(age) { return /^\d+$/.test(String(age))&&Number(age)>=14&&Number(age)<18; }
