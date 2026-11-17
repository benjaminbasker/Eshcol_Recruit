-- 17 Nov 2026 (Benjamin: no locality list for Chennai): many more Chennai localities, Eshcol's home city, each with its
-- zone (North, Central, West, South, OMR and ECR), and common spellings. Existing Chennai rows get their zone too.
-- INSERT IGNORE: a term already in the dictionary (any category) is left as it is.
INSERT IGNORE INTO skills_dictionary (term, canonical, category, area) VALUES
('tondiarpet', 'Tondiarpet', 'locality', 'Chennai|North'),
('royapuram', 'Royapuram', 'locality', 'Chennai|North'),
('washermanpet', 'Washermanpet', 'locality', 'Chennai|North'),
('vyasarpadi', 'Vyasarpadi', 'locality', 'Chennai|North'),
('kolathur', 'Kolathur', 'locality', 'Chennai|North'),
('madhavaram', 'Madhavaram', 'locality', 'Chennai|North'),
('moolakadai', 'Moolakadai', 'locality', 'Chennai|North'),
('ennore', 'Ennore', 'locality', 'Chennai|North'),
('tiruvottiyur', 'Tiruvottiyur', 'locality', 'Chennai|North'),
('manali', 'Manali', 'locality', 'Chennai|North'),
('red hills', 'Red Hills', 'locality', 'Chennai|North'),
('puzhal', 'Puzhal', 'locality', 'Chennai|North'),
('perambur', 'Perambur', 'locality', 'Chennai|North'),
('villivakkam', 'Villivakkam', 'locality', 'Chennai|North'),
('ayanavaram', 'Ayanavaram', 'locality', 'Chennai|North'),
('kodungaiyur', 'Kodungaiyur', 'locality', 'Chennai|North'),
('periyar nagar', 'Periyar Nagar', 'locality', 'Chennai|North'),
('sembiam', 'Sembiam', 'locality', 'Chennai|North'),
('ponniammanmedu', 'Ponniammanmedu', 'locality', 'Chennai|North'),
('retteri', 'Retteri', 'locality', 'Chennai|North'),
('madhavaram milk colony', 'Madhavaram Milk Colony', 'locality', 'Chennai|North'),
('ponneri', 'Ponneri', 'locality', 'Chennai|North'),
('gummidipoondi', 'Gummidipoondi', 'locality', 'Chennai|North'),
('minjur', 'Minjur', 'locality', 'Chennai|North'),
('egmore', 'Egmore', 'locality', 'Chennai|Central'),
('kilpauk', 'Kilpauk', 'locality', 'Chennai|Central'),
('nungambakkam', 'Nungambakkam', 'locality', 'Chennai|Central'),
('t nagar', 'T Nagar', 'locality', 'Chennai|Central'),
('mylapore', 'Mylapore', 'locality', 'Chennai|Central'),
('royapettah', 'Royapettah', 'locality', 'Chennai|Central'),
('teynampet', 'Teynampet', 'locality', 'Chennai|Central'),
('alwarpet', 'Alwarpet', 'locality', 'Chennai|Central'),
('gopalapuram', 'Gopalapuram', 'locality', 'Chennai|Central'),
('triplicane', 'Triplicane', 'locality', 'Chennai|Central'),
('chetpet', 'Chetpet', 'locality', 'Chennai|Central'),
('purasawalkam', 'Purasawalkam', 'locality', 'Chennai|Central'),
('anna salai', 'Anna Salai', 'locality', 'Chennai|Central'),
('mandaveli', 'Mandaveli', 'locality', 'Chennai|Central'),
('abhiramapuram', 'Abhiramapuram', 'locality', 'Chennai|Central'),
('ra puram', 'RA Puram', 'locality', 'Chennai|Central'),
('choolaimedu', 'Choolaimedu', 'locality', 'Chennai|Central'),
('aminjikarai', 'Aminjikarai', 'locality', 'Chennai|Central'),
('shenoy nagar', 'Shenoy Nagar', 'locality', 'Chennai|Central'),
('arumbakkam', 'Arumbakkam', 'locality', 'Chennai|Central'),
('george town', 'George Town', 'locality', 'Chennai|Central'),
('park town', 'Park Town', 'locality', 'Chennai|Central'),
('vepery', 'Vepery', 'locality', 'Chennai|Central'),
('chintadripet', 'Chintadripet', 'locality', 'Chennai|Central'),
('thousand lights', 'Thousand Lights', 'locality', 'Chennai|Central'),
('west mambalam', 'West Mambalam', 'locality', 'Chennai|Central'),
('mambalam', 'Mambalam', 'locality', 'Chennai|Central'),
('nandanam', 'Nandanam', 'locality', 'Chennai|Central'),
('cit nagar', 'CIT Nagar', 'locality', 'Chennai|Central'),
('anna nagar', 'Anna Nagar', 'locality', 'Chennai|West'),
('mogappair', 'Mogappair', 'locality', 'Chennai|West'),
('porur', 'Porur', 'locality', 'Chennai|West'),
('vadapalani', 'Vadapalani', 'locality', 'Chennai|West'),
('kk nagar', 'KK Nagar', 'locality', 'Chennai|West'),
('kodambakkam', 'Kodambakkam', 'locality', 'Chennai|West'),
('ashok nagar', 'Ashok Nagar', 'locality', 'Chennai|West'),
('saligramam', 'Saligramam', 'locality', 'Chennai|West'),
('virugambakkam', 'Virugambakkam', 'locality', 'Chennai|West'),
('valasaravakkam', 'Valasaravakkam', 'locality', 'Chennai|West'),
('ramapuram', 'Ramapuram', 'locality', 'Chennai|West'),
('koyambedu', 'Koyambedu', 'locality', 'Chennai|West'),
('maduravoyal', 'Maduravoyal', 'locality', 'Chennai|West'),
('ambattur', 'Ambattur', 'locality', 'Chennai|West'),
('avadi', 'Avadi', 'locality', 'Chennai|West'),
('padi', 'Padi', 'locality', 'Chennai|West'),
('thirumangalam', 'Thirumangalam', 'locality', 'Chennai|West'),
('nolambur', 'Nolambur', 'locality', 'Chennai|West'),
('thiruverkadu', 'Thiruverkadu', 'locality', 'Chennai|West'),
('iyyappanthangal', 'Iyyappanthangal', 'locality', 'Chennai|West'),
('mangadu', 'Mangadu', 'locality', 'Chennai|West'),
('poonamallee', 'Poonamallee', 'locality', 'Chennai|West'),
('thirumullaivoyal', 'Thirumullaivoyal', 'locality', 'Chennai|West'),
('pattabiram', 'Pattabiram', 'locality', 'Chennai|West'),
('kattupakkam', 'Kattupakkam', 'locality', 'Chennai|West'),
('alapakkam', 'Alapakkam', 'locality', 'Chennai|West'),
('vanagaram', 'Vanagaram', 'locality', 'Chennai|West'),
('karambakkam', 'Karambakkam', 'locality', 'Chennai|West'),
('kundrathur', 'Kundrathur', 'locality', 'Chennai|West'),
('sriperumbudur', 'Sriperumbudur', 'locality', 'Chennai|West'),
('oragadam', 'Oragadam', 'locality', 'Chennai|West'),
('irungattukottai', 'Irungattukottai', 'locality', 'Chennai|West'),
('tiruvallur', 'Tiruvallur', 'locality', 'Chennai|West'),
('thirumazhisai', 'Thirumazhisai', 'locality', 'Chennai|West'),
('anna nagar west', 'Anna Nagar West', 'locality', 'Chennai|West'),
('ambattur industrial estate', 'Ambattur Industrial Estate', 'locality', 'Chennai|West'),
('korattur', 'Korattur', 'locality', 'Chennai|West'),
('adyar', 'Adyar', 'locality', 'Chennai|South'),
('besant nagar', 'Besant Nagar', 'locality', 'Chennai|South'),
('thiruvanmiyur', 'Thiruvanmiyur', 'locality', 'Chennai|South'),
('velachery', 'Velachery', 'locality', 'Chennai|South'),
('guindy', 'Guindy', 'locality', 'Chennai|South'),
('saidapet', 'Saidapet', 'locality', 'Chennai|South'),
('alandur', 'Alandur', 'locality', 'Chennai|South'),
('st thomas mount', 'St Thomas Mount', 'locality', 'Chennai|South'),
('nanganallur', 'Nanganallur', 'locality', 'Chennai|South'),
('madipakkam', 'Madipakkam', 'locality', 'Chennai|South'),
('adambakkam', 'Adambakkam', 'locality', 'Chennai|South'),
('pallikaranai', 'Pallikaranai', 'locality', 'Chennai|South'),
('keelkattalai', 'Keelkattalai', 'locality', 'Chennai|South'),
('medavakkam', 'Medavakkam', 'locality', 'Chennai|South'),
('chromepet', 'Chromepet', 'locality', 'Chennai|South'),
('pallavaram', 'Pallavaram', 'locality', 'Chennai|South'),
('tambaram', 'Tambaram', 'locality', 'Chennai|South'),
('east tambaram', 'East Tambaram', 'locality', 'Chennai|South'),
('west tambaram', 'West Tambaram', 'locality', 'Chennai|South'),
('selaiyur', 'Selaiyur', 'locality', 'Chennai|South'),
('perungalathur', 'Perungalathur', 'locality', 'Chennai|South'),
('vandalur', 'Vandalur', 'locality', 'Chennai|South'),
('urapakkam', 'Urapakkam', 'locality', 'Chennai|South'),
('guduvancheri', 'Guduvancheri', 'locality', 'Chennai|South'),
('mudichur', 'Mudichur', 'locality', 'Chennai|South'),
('pammal', 'Pammal', 'locality', 'Chennai|South'),
('kovilambakkam', 'Kovilambakkam', 'locality', 'Chennai|South'),
('nemilichery', 'Nemilichery', 'locality', 'Chennai|South'),
('hasthinapuram', 'Hasthinapuram', 'locality', 'Chennai|South'),
('ekkattuthangal', 'Ekkattuthangal', 'locality', 'Chennai|South'),
('meenambakkam', 'Meenambakkam', 'locality', 'Chennai|South'),
('kotturpuram', 'Kotturpuram', 'locality', 'Chennai|South'),
('indira nagar', 'Indira Nagar', 'locality', 'Chennai|South'),
('taramani', 'Taramani', 'locality', 'Chennai|South'),
('jaladampet', 'Jaladampet', 'locality', 'Chennai|South'),
('sembakkam', 'Sembakkam', 'locality', 'Chennai|South'),
('gowrivakkam', 'Gowrivakkam', 'locality', 'Chennai|South'),
('rajakilpakkam', 'Rajakilpakkam', 'locality', 'Chennai|South'),
('kilkattalai', 'Kilkattalai', 'locality', 'Chennai|South'),
('maraimalai nagar', 'Maraimalai Nagar', 'locality', 'Chennai|South'),
('chengalpattu', 'Chengalpattu', 'locality', 'Chennai|South'),
('singaperumal koil', 'Singaperumal Koil', 'locality', 'Chennai|South'),
('potheri', 'Potheri', 'locality', 'Chennai|South'),
('kattankulathur', 'Kattankulathur', 'locality', 'Chennai|South'),
('ullagaram', 'Ullagaram', 'locality', 'Chennai|South'),
('puzhuthivakkam', 'Puzhuthivakkam', 'locality', 'Chennai|South'),
('thiruneermalai', 'Thiruneermalai', 'locality', 'Chennai|South'),
('omr', 'OMR', 'locality', 'Chennai|OMR and ECR'),
('perungudi', 'Perungudi', 'locality', 'Chennai|OMR and ECR'),
('thoraipakkam', 'Thoraipakkam', 'locality', 'Chennai|OMR and ECR'),
('sholinganallur', 'Sholinganallur', 'locality', 'Chennai|OMR and ECR'),
('navalur', 'Navalur', 'locality', 'Chennai|OMR and ECR'),
('siruseri', 'Siruseri', 'locality', 'Chennai|OMR and ECR'),
('kelambakkam', 'Kelambakkam', 'locality', 'Chennai|OMR and ECR'),
('karapakkam', 'Karapakkam', 'locality', 'Chennai|OMR and ECR'),
('semmancheri', 'Semmancheri', 'locality', 'Chennai|OMR and ECR'),
('padur', 'Padur', 'locality', 'Chennai|OMR and ECR'),
('palavakkam', 'Palavakkam', 'locality', 'Chennai|OMR and ECR'),
('neelankarai', 'Neelankarai', 'locality', 'Chennai|OMR and ECR'),
('injambakkam', 'Injambakkam', 'locality', 'Chennai|OMR and ECR'),
('ecr', 'ECR', 'locality', 'Chennai|OMR and ECR'),
('kandanchavadi', 'Kandanchavadi', 'locality', 'Chennai|OMR and ECR'),
('kottivakkam', 'Kottivakkam', 'locality', 'Chennai|OMR and ECR'),
('thalambur', 'Thalambur', 'locality', 'Chennai|OMR and ECR'),
('egattur', 'Egattur', 'locality', 'Chennai|OMR and ECR'),
('kazhipattur', 'Kazhipattur', 'locality', 'Chennai|OMR and ECR'),
('mettukuppam', 'Mettukuppam', 'locality', 'Chennai|OMR and ECR'),
('uthandi', 'Uthandi', 'locality', 'Chennai|OMR and ECR'),
('akkarai', 'Akkarai', 'locality', 'Chennai|OMR and ECR'),
('muttukadu', 'Muttukadu', 'locality', 'Chennai|OMR and ECR'),
('okkiyampet', 'Okkiyampet', 'locality', 'Chennai|OMR and ECR'),
('r a puram', 'RA Puram', 'locality', 'Chennai|Central'),
('r.a. puram', 'RA Puram', 'locality', 'Chennai|Central'),
('raja annamalaipuram', 'RA Puram', 'locality', 'Chennai|Central'),
('raja annamalai puram', 'RA Puram', 'locality', 'Chennai|Central'),
('st. thomas mount', 'St Thomas Mount', 'locality', 'Chennai|South'),
('st.thomas mount', 'St Thomas Mount', 'locality', 'Chennai|South'),
('parangimalai', 'St Thomas Mount', 'locality', 'Chennai|South'),
('valasarawakkam', 'Valasaravakkam', 'locality', 'Chennai|West'),
('valasaravakam', 'Valasaravakkam', 'locality', 'Chennai|West'),
('virugambakam', 'Virugambakkam', 'locality', 'Chennai|West'),
('tiruvottriyur', 'Tiruvottiyur', 'locality', 'Chennai|North'),
('thiruvottiyur', 'Tiruvottiyur', 'locality', 'Chennai|North'),
('thiruvallur', 'Tiruvallur', 'locality', 'Chennai|West'),
('tirumazhisai', 'Thirumazhisai', 'locality', 'Chennai|West'),
('sriperumbattur', 'Sriperumbudur', 'locality', 'Chennai|West'),
('chengalpet', 'Chengalpattu', 'locality', 'Chennai|South'),
('chengleput', 'Chengalpattu', 'locality', 'Chennai|South'),
('pallavaram cantonment', 'Pallavaram', 'locality', 'Chennai|South'),
('chrompet', 'Chromepet', 'locality', 'Chennai|South'),
('tambaram east', 'East Tambaram', 'locality', 'Chennai|South'),
('tambaram west', 'West Tambaram', 'locality', 'Chennai|South'),
('kk nagar chennai', 'KK Nagar', 'locality', 'Chennai|West'),
('k.k. nagar', 'KK Nagar', 'locality', 'Chennai|West'),
('ashoknagar', 'Ashok Nagar', 'locality', 'Chennai|West'),
('purasaiwakkam', 'Purasawalkam', 'locality', 'Chennai|Central'),
('purasawakkam', 'Purasawalkam', 'locality', 'Chennai|Central'),
('perambur barracks', 'Perambur', 'locality', 'Chennai|North'),
('tnagar', 'T Nagar', 'locality', 'Chennai|Central'),
('t. nagar', 'T Nagar', 'locality', 'Chennai|Central'),
('old mahabalipuram road', 'OMR', 'locality', 'Chennai|OMR and ECR'),
('east coast road', 'ECR', 'locality', 'Chennai|OMR and ECR'),
('okkiyam thoraipakkam', 'Thoraipakkam', 'locality', 'Chennai|OMR and ECR'),
('thoraipakam', 'Thoraipakkam', 'locality', 'Chennai|OMR and ECR'),
('sholinganallur omr', 'Sholinganallur', 'locality', 'Chennai|OMR and ECR'),
('medavakam', 'Medavakkam', 'locality', 'Chennai|South'),
('madipakam', 'Madipakkam', 'locality', 'Chennai|South'),
('nanganallur chennai', 'Nanganallur', 'locality', 'Chennai|South'),
('ambattur ot', 'Ambattur', 'locality', 'Chennai|West'),
('avadi chennai', 'Avadi', 'locality', 'Chennai|West'),
('anna nagar east', 'Anna Nagar', 'locality', 'Chennai|West'),
('annanagar', 'Anna Nagar', 'locality', 'Chennai|West'),
('annanagar west', 'Anna Nagar West', 'locality', 'Chennai|West'),
('kolathur chennai', 'Kolathur', 'locality', 'Chennai|North'),
('redhills', 'Red Hills', 'locality', 'Chennai|North'),
('george town chennai', 'George Town', 'locality', 'Chennai|Central'),
('parrys', 'George Town', 'locality', 'Chennai|Central');
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Tondiarpet';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Royapuram';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Washermanpet';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Vyasarpadi';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kolathur';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Madhavaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Moolakadai';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ennore';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Tiruvottiyur';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Manali';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Red Hills';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Puzhal';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Perambur';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Villivakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ayanavaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kodungaiyur';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Periyar Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Sembiam';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ponniammanmedu';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Retteri';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Madhavaram Milk Colony';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ponneri';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Gummidipoondi';
;;
UPDATE skills_dictionary SET area = 'Chennai|North' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Minjur';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Egmore';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kilpauk';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Nungambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'T Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mylapore';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Royapettah';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Teynampet';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Alwarpet';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Gopalapuram';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Triplicane';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Chetpet';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Purasawalkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Anna Salai';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mandaveli';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Abhiramapuram';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'RA Puram';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Choolaimedu';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Aminjikarai';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Shenoy Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Arumbakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'George Town';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Park Town';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Vepery';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Chintadripet';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thousand Lights';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'West Mambalam';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mambalam';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Nandanam';
;;
UPDATE skills_dictionary SET area = 'Chennai|Central' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'CIT Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Anna Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mogappair';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Porur';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Vadapalani';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'KK Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kodambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ashok Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Saligramam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Virugambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Valasaravakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ramapuram';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Koyambedu';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Maduravoyal';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ambattur';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Avadi';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Padi';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thirumangalam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Nolambur';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thiruverkadu';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Iyyappanthangal';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mangadu';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Poonamallee';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thirumullaivoyal';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Pattabiram';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kattupakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Alapakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Vanagaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Karambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kundrathur';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Sriperumbudur';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Oragadam';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Irungattukottai';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Tiruvallur';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thirumazhisai';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Anna Nagar West';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ambattur Industrial Estate';
;;
UPDATE skills_dictionary SET area = 'Chennai|West' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Korattur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Adyar';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Besant Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thiruvanmiyur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Velachery';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Guindy';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Saidapet';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Alandur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'St Thomas Mount';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Nanganallur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Madipakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Adambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Pallikaranai';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Keelkattalai';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Medavakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Chromepet';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Pallavaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Tambaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'East Tambaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'West Tambaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Selaiyur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Perungalathur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Vandalur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Urapakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Guduvancheri';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mudichur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Pammal';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kovilambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Nemilichery';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Hasthinapuram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ekkattuthangal';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Meenambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kotturpuram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Indira Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Taramani';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Jaladampet';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Sembakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Gowrivakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Rajakilpakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kilkattalai';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Maraimalai Nagar';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Chengalpattu';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Singaperumal Koil';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Potheri';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kattankulathur';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Ullagaram';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Puzhuthivakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|South' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thiruneermalai';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'OMR';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Perungudi';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thoraipakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Sholinganallur';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Navalur';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Siruseri';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kelambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Karapakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Semmancheri';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Padur';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Palavakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Neelankarai';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Injambakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'ECR';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kandanchavadi';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kottivakkam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Thalambur';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Egattur';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Kazhipattur';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Mettukuppam';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Uthandi';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Akkarai';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Muttukadu';
;;
UPDATE skills_dictionary SET area = 'Chennai|OMR and ECR' WHERE category = 'locality' AND area = 'Chennai|' AND canonical = 'Okkiyampet';
;;
