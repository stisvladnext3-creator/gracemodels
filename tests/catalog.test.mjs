import test from 'node:test';
import assert from 'node:assert/strict';
import { filterModels } from '../public/catalog.js';
const models = [
  {id:'a',name:'Алина',name_en:'Alina',gender:'women',category:'main',height:177,bust:84,waist:60,hips:90,shoe:39,hair:'brown',eyes:'brown'},
  {id:'b',name:'Ева',name_en:'Eva',gender:'women',category:'new',height:180,bust:83,waist:62,hips:91,shoe:40,hair:'blonde',eyes:'green'},
  {id:'c',name:'Марк',name_en:'Mark',gender:'men',category:'main',height:188,bust:98,waist:78,hips:95,shoe:44,hair:'brown',eyes:'green'}
];
test('measurement filters combine inclusively with category and appearance',()=>{
  assert.deepEqual(filterModels(models,{gender:'women',min:'177',max:'180',bust_min:'83',bust_max:'84',waist_max:'60',hips_min:'90',hair:'brown'}).map(x=>x.id),['a']);
  assert.deepEqual(filterModels(models,{gender:'women',category:'new',eyes:'green',shoe:'40'}).map(x=>x.id),['b']);
  assert.equal(filterModels(models,{min:'190',max:'175'}).length,0);
});
test('name search supports both languages and can be combined with saved models',()=>{
  assert.deepEqual(filterModels(models,{q:' АЛИНА '}).map(x=>x.id),['a']);
  assert.deepEqual(filterModels(models,{q:'eva',saved:true},['b']).map(x=>x.id),['b']);
  assert.equal(filterModels(models,{q:'eva',saved:true},['a']).length,0);
  assert.equal(filterModels(models,{}).length,3);
});
