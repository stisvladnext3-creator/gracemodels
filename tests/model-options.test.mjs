import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { hairColors, eyeColors } from '../public/model-options.js';
import { renderFormField } from '../public/form-fields.js';
import { filterModels } from '../public/catalog.js';
import { validateModel } from '../worker/index.mjs';

test('screenshot colours are available in both languages and keep legacy values',()=>{
  assert.deepEqual(Object.values(hairColors).map(titles=>titles[1]),['Auburn','Bald','Black','Blond','Brown','Dark blond','Dark brown','Grey','Light blond','Light brown','Red','Strawberry blond','White','Other']);
  assert.deepEqual(Object.values(eyeColors).map(titles=>titles[1]),['Black','Blue','Blue gray','Blue green','Brown','Green','Green brown','Green gray','Grey','Hazel','Veron (heterochromia)','Other']);
  for(const code of ['blonde','light_brown','brown','black','red','gray','other'])assert.ok(hairColors[code]);
  for(const code of ['blue','brown','green','gray','hazel','gray_blue','gray_green','other'])assert.ok(eyeColors[code]);
  for(const choices of [hairColors,eyeColors])for(const titles of Object.values(choices))assert.ok(titles[0]&&titles[1]);
});

test('application choices match the catalogue and render RU and EN without changing stored values',()=>{
  const defaults=JSON.parse(readFileSync(new URL('../app/site-defaults.json',import.meta.url)));
  for(const [key,choices] of Object.entries({hair:hairColors,eyes:eyeColors})){
    const field=defaults.forms.application.fields.find(field=>field.key===key);
    assert.deepEqual(field.options,Object.entries(choices).map(([value,titles])=>[value,titles[0]]));
    for(const [index,lang] of ['ru','en'].entries()){
      const html=renderFormField(field,[],'',lang);
      for(const [value,titles] of Object.entries(choices))assert.ok(html.includes(`value="${value}" >${titles[index]}</option>`));
    }
  }
});

test('all new and existing colours survive validation and filter independently',()=>{
  const base={name:'Example',slug:'example',height:175,bust:84,waist:61,hips:90,shoe:39,hair:'brown',eyes:'brown'};
  for(const [key,choices] of Object.entries({hair:hairColors,eyes:eyeColors})){
    const models=Object.keys(choices).map(value=>({id:value,...base,[key]:value}));
    for(const value of Object.keys(choices)){
      assert.equal(validateModel({...base,[key]:value})[key],value);
      assert.deepEqual(filterModels(models,{[key]:value}).map(model=>model.id),[value]);
    }
  }
  const mixed=[{...base,id:'match',hair:'strawberry_blonde',eyes:'blue_green'},{...base,id:'wrong-eyes',hair:'strawberry_blonde',eyes:'gray_blue'},{...base,id:'wrong-hair',hair:'auburn',eyes:'blue_green'}];
  assert.deepEqual(filterModels(mixed,{hair:'strawberry_blonde',eyes:'blue_green'}).map(model=>model.id),['match']);
});
