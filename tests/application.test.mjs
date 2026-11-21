import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateAge } from '../public/application.js';

test('age calculation includes candidates aged 14, 15, 16 and 17',()=>{
  for (const age of [14,15,16,17]) assert.equal(calculateAge(`${2026-age}-11-21`,'2026-11-21'),age);
  assert.equal(calculateAge('2012-11-22','2026-11-21'),13);
  assert.equal(calculateAge('2012-11-20','2026-11-21'),14);
});

test('age calculation rejects invalid or future dates',()=>{
  for (const value of ['','2026-13-01','2026-02-30','2026-11-22','not-a-date']) assert.equal(calculateAge(value,'2026-11-21'),null);
  assert.equal(calculateAge('2012-02-29','2026-03-01'),14);
});
