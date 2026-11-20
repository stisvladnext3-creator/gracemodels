export function filterModels(models, filters = {}, saved = []) {
  const normalize = value => String(value || '').normalize('NFKC').toLocaleLowerCase().replaceAll('ё', 'е').trim();
  const words = normalize(filters.q).split(/\s+/).filter(Boolean);
  return models.filter(model => {
    if (filters.gender && model.gender !== filters.gender) return false;
    if (filters.category && model.category !== filters.category) return false;
    if (filters.saved && !saved.includes(model.id)) return false;
    const fullName = normalize([model.name, model.name_en, model.first_name, model.last_name].filter(Boolean).join(' '));
    if (words.some(word => !fullName.includes(word))) return false;
    if (filters.hair && model.hair !== filters.hair) return false;
    if (filters.eyes && model.eyes !== filters.eyes) return false;
    if (filters.bust_cup && model.bust_cup !== filters.bust_cup) return false;
    if (filters.shoe && model.shoe !== Number(filters.shoe)) return false;
    if (filters.min && model.height < Number(filters.min)) return false;
    if (filters.max && model.height > Number(filters.max)) return false;
    for (const measurement of ['bust', 'waist', 'hips']) {
      if (filters[measurement + '_min'] && model[measurement] < Number(filters[measurement + '_min'])) return false;
      if (filters[measurement + '_max'] && model[measurement] > Number(filters[measurement + '_max'])) return false;
    }
    return true;
  });
}
