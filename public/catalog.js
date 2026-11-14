export function filterModels(models, filters = {}, saved = []) {
  const query = String(filters.q || '').trim().toLocaleLowerCase();
  return models.filter(model => {
    if (filters.gender && model.gender !== filters.gender) return false;
    if (filters.category && model.category !== filters.category) return false;
    if (filters.saved && !saved.includes(model.id)) return false;
    if (query && !(model.name + ' ' + model.name_en).toLocaleLowerCase().includes(query)) return false;
    if (filters.hair && model.hair !== filters.hair) return false;
    if (filters.eyes && model.eyes !== filters.eyes) return false;
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
