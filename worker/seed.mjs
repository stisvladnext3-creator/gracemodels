export const defaultSettings = {
  name:'FORMA', descriptor:'MODEL MANAGEMENT', city:'Москва', city_en:'Moscow',
  email:'hello@forma.agency', phone:'', telegram:'', instagram:'', vk:'',
  tagline:'Лица, которые запоминают.', tagline_en:'Faces you remember.',
  about:'Мы видим характер за внешностью. FORMA — пространство для новых лиц, сильных индивидуальностей и творческого сотрудничества. Мы соединяем моделей и команды, которым близок внимательный, современный взгляд на моду.',
  about_en:'We see the character behind the face. FORMA is a space for new talent, strong personalities and creative collaborations. We connect models with teams that share a thoughtful, contemporary perspective on fashion.',
  seo_title:'FORMA — модельное агентство', seo_description:'Модели, новые лица и творческое сотрудничество. Каталог и заявки в модельное агентство FORMA.',
  hero_image:'/images/woman-02.jpg', hero_secondary:'/images/man-04.jpg', demo_mode:true
};
const templates = [
 ['alina','Алина М.','Alina M.','women','main','woman-02.jpg',178,84,61,90,39,'brown','brown'],
 ['sofia','София К.','Sofia K.','women','new','woman-03.jpg',176,82,60,89,38,'brown','blue'],
 ['alex','Алекс Р.','Alex R.','men','main','man-04.jpg',188,96,78,94,44,'brown','brown'],
 ['eva','Ева Н.','Eva N.','women','international','woman-05.jpg',179,83,60,90,39,'blonde','green'],
 ['mark','Марк А.','Mark A.','men','new','man-01.jpg',186,98,79,96,43,'brown','green'],
 ['maria','Мария С.','Maria S.','women','main','woman-06.jpg',175,84,62,91,38,'brown','brown'],
 ['anna','Анна Л.','Anna L.','women','new','woman-07.jpg',180,82,60,89,40,'blonde','blue'],
 ['nikita','Никита В.','Nikita V.','men','international','man-02.jpg',189,97,77,95,44,'brown','blue'],
 ['daria','Дарья Т.','Daria T.','women','main','woman-01.jpg',177,85,61,91,39,'black','brown'],
 ['daniel','Даниэль К.','Daniel K.','men','main','man-05.jpg',187,95,76,93,43,'black','brown'],
 ['liza','Лиза Р.','Liza R.','women','international','woman-04.jpg',178,83,59,90,39,'brown','green'],
 ['max','Макс Д.','Max D.','men','new','man-03.jpg',185,96,78,94,43,'brown','brown']
];
export const seedModels = templates.map((m,i)=>({id:'demo-'+m[0],slug:m[0],name:m[1],name_en:m[2],gender:m[3],category:m[4],cover:'/images/'+m[5],gallery:['/images/'+m[5]],height:m[6],bust:m[7],waist:m[8],hips:m[9],shoe:m[10],hair:m[11],eyes:m[12],city:'Москва',bio:'Демонстрационная анкета. Замените фотографии и данные в панели агентства.',bio_en:'Illustrative profile. Replace the photographs and details in your agency studio.',published:1,featured:i<4?1:0,demo:1,sort_order:i}));
export const seedNews = [
 {id:'editorial-portfolio',title:'Портфолио начинается с вас',title_en:'Your portfolio starts with you',summary:'Простые фотографии, естественный свет и ваша индивидуальность. Что нужно для первого знакомства с агентством.',summary_en:'Simple photographs, natural light and your individuality. Everything you need for your first agency introduction.',body:'Для заявки не нужна профессиональная съемка. Подготовьте портрет анфас, профиль и фотографию в полный рост. Выберите однотонную одежду, естественный свет и спокойный фон. Не используйте фильтры и сильную ретушь. В анкете укажите актуальные параметры и контакты, по которым с вами удобно связаться.',body_en:'You do not need a professional shoot to apply. Prepare a front portrait, a profile photograph and a full-length shot. Choose simple clothes, natural light and a neutral background. Avoid filters and heavy retouching. Include current measurements and contact details.',image:'/images/woman-04.jpg',published:1,published_at:'2026-11-01'},
 {id:'editorial-first-shoot',title:'Перед первой съемкой',title_en:'Before your first shoot',summary:'Как подготовиться к встрече с командой и чувствовать себя свободнее перед камерой.',summary_en:'How to prepare for a creative team and feel more comfortable in front of the camera.',body:'Заранее уточните время, место и задачу съемки. Сохраните контакты координатора, подготовьте базовую одежду по списку команды и оставьте время на дорогу. Если у вас есть вопросы, задавайте их до съемки. Хороший результат начинается с понятной коммуникации и взаимного уважения.',body_en:'Confirm the time, place and brief in advance. Save the coordinator’s contact, prepare any requested basics and allow time to travel. Ask questions before the shoot. Strong work starts with clear communication and mutual respect.',image:'/images/man-04.jpg',published:1,published_at:'2026-10-28'}
];
