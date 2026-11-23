import test from 'node:test';
import assert from 'node:assert/strict';
import { renderFormField, needsGuardian } from '../public/form-fields.js';

test('guardian confirmation is shown only for supported ages below 18',()=>{
  for(const age of [14,15,16,17,'14','17'])assert.equal(needsGuardian(age),true);
  for(const age of ['',13,18,21,'14.5','not a number'])assert.equal(needsGuardian(age),false);
});
test('form labels and help are escaped and hidden fields are omitted',()=>{
  const field={key:'custom_test',type:'text',label:'<script>test</script>',placeholder:'" onfocus="bad',help:'<b>help</b>',required:true,visible:true};
  const html=renderFormField(field);
  assert.match(html,/&lt;script&gt;/);assert.match(html,/&quot;/);assert.match(html,/&lt;b&gt;help&lt;\/b&gt;/);assert.match(html,/required/);
  assert.equal(renderFormField({...field,visible:false}),'');
});
test('select choices and booking model selection retain distinct values',()=>{
  const field={key:'custom_list',type:'select',label:'Выбор',visible:true,options:[['one','Первый'],['two','Второй']]};
  assert.match(renderFormField(field),/value="two"\s*>Второй/);
  const html=renderFormField({...field,key:'model_id',type:'model'},[{id:'abc',name:'Алёна'}],'abc');
  assert.match(html,/value="abc" selected>Алёна/);
});
