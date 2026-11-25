import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source=readFileSync(new URL('../public/admin.js',import.meta.url),'utf8');
const start=source.indexOf('function mailDelivery('),end=source.indexOf('\nfunction ',start+1);
const context={esc:value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;'),date:value=>value};
runInNewContext(source.slice(start,end),context);
const render=status=>context.mailDelivery({id:'fixture-inquiry',notifications:[{recipient:'info@grace-models.ru',status,sent_at:'',last_error:''}]});

test('admin can resume pending, failed and missing recipient deliveries',()=>{
  for(const status of ['pending','retry','failed','missing'])assert.match(render(status),/data-retry-mail="fixture-inquiry"/);
  assert.match(render('missing'),/Не поставлено в очередь/);
});

test('accepted and cancelled deliveries are not offered for resending',()=>{
  assert.doesNotMatch(render('sent'),/data-retry-mail/);
  assert.doesNotMatch(render('cancelled'),/data-retry-mail/);
  assert.match(render('cancelled'),/Отправка отменена/);
});

test('mail status messages are escaped and the legacy service address stays masked',()=>{
  const html=context.mailDelivery({id:'fixture-inquiry',notifications:[{recipient:'Info@grace-models.com',status:'retry',sent_at:'',last_error:'<img src=x onerror=alert(1)>'}]});
  assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img/);
  assert.doesNotMatch(html,/info@grace-models\.com/i);
});
