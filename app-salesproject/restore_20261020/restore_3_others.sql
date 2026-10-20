USE appsalesproject_db;
-- ขั้น C2–C5 รหัสงานประมูล / แหล่งที่มา / ลบประวัติซ้ำ / ตัวนับรหัส
START TRANSACTION;
-- C2) รหัส PJ งานประมูล
UPDATE project_assignments SET project_code = 'PJ-260903-001', updated_at = updated_at WHERE id = 17 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260904-001', updated_at = updated_at WHERE id = 18 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260904-002', updated_at = updated_at WHERE id = 19 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260904-003', updated_at = updated_at WHERE id = 20 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260907-001', updated_at = updated_at WHERE id = 21 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260907-002', updated_at = updated_at WHERE id = 22 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260907-003', updated_at = updated_at WHERE id = 23 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260908-001', updated_at = updated_at WHERE id = 24 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260908-002', updated_at = updated_at WHERE id = 25 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260908-003', updated_at = updated_at WHERE id = 26 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260908-004', updated_at = updated_at WHERE id = 27 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260908-005', updated_at = updated_at WHERE id = 28 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260908-006', updated_at = updated_at WHERE id = 29 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260117-001', updated_at = updated_at WHERE id = 30 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260117-002', updated_at = updated_at WHERE id = 31 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260212-001', updated_at = updated_at WHERE id = 34 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260227-001', updated_at = updated_at WHERE id = 35 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260508-001', updated_at = updated_at WHERE id = 38 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260519-001', updated_at = updated_at WHERE id = 39 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260521-001', updated_at = updated_at WHERE id = 40 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260618-001', updated_at = updated_at WHERE id = 42 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260626-001', updated_at = updated_at WHERE id = 43 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260706-001', updated_at = updated_at WHERE id = 44 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260715-001', updated_at = updated_at WHERE id = 46 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260721-001', updated_at = updated_at WHERE id = 47 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260723-001', updated_at = updated_at WHERE id = 48 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260910-001', updated_at = updated_at WHERE id = 49 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260910-002', updated_at = updated_at WHERE id = 50 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260910-003', updated_at = updated_at WHERE id = 51 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260910-004', updated_at = updated_at WHERE id = 52 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260911-001', updated_at = updated_at WHERE id = 53 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260911-002', updated_at = updated_at WHERE id = 54 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260911-003', updated_at = updated_at WHERE id = 55 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260915-001', updated_at = updated_at WHERE id = 56 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260915-002', updated_at = updated_at WHERE id = 57 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260915-003', updated_at = updated_at WHERE id = 58 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260915-004', updated_at = updated_at WHERE id = 59 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260916-001', updated_at = updated_at WHERE id = 60 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260917-001', updated_at = updated_at WHERE id = 61 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260918-001', updated_at = updated_at WHERE id = 62 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260918-002', updated_at = updated_at WHERE id = 63 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260921-001', updated_at = updated_at WHERE id = 64 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260921-002', updated_at = updated_at WHERE id = 65 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260922-001', updated_at = updated_at WHERE id = 66 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260923-001', updated_at = updated_at WHERE id = 67 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260923-002', updated_at = updated_at WHERE id = 68 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260924-001', updated_at = updated_at WHERE id = 69 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260928-001', updated_at = updated_at WHERE id = 70 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260928-002', updated_at = updated_at WHERE id = 71 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260928-003', updated_at = updated_at WHERE id = 72 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260928-004', updated_at = updated_at WHERE id = 73 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260929-001', updated_at = updated_at WHERE id = 74 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-260930-001', updated_at = updated_at WHERE id = 75 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261001-001', updated_at = updated_at WHERE id = 76 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261002-001', updated_at = updated_at WHERE id = 77 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261006-002', updated_at = updated_at WHERE id = 78 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261008-001', updated_at = updated_at WHERE id = 79 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261009-001', updated_at = updated_at WHERE id = 80 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261014-001', updated_at = updated_at WHERE id = 81 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261014-002', updated_at = updated_at WHERE id = 82 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261015-001', updated_at = updated_at WHERE id = 83 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261015-002', updated_at = updated_at WHERE id = 84 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261016-002', updated_at = updated_at WHERE id = 85 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261019-001', updated_at = updated_at WHERE id = 86 AND project_code IS NULL;
UPDATE project_assignments SET project_code = 'PJ-261020-001', updated_at = updated_at WHERE id = 87 AND project_code IS NULL;

-- C3) แหล่งที่มา self_sourced (821 ดีล — เฉพาะที่ตอนนี้เป็นค่าว่าง)
UPDATE pipeline_items SET source_type = 'self_sourced', updated_at = updated_at WHERE source_type = '' AND id IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117,118,119,120,121,122,123,124,125,126,127,128,129,130,131,132,133,134,135,136,137,138,139,140,141,143,146,147,148,149,150,151,152,153,154,156,157,158,159,160,161,162,163,191,198,199,200,201,202,203,204,205,206,207,208,209,210,211,212,213,214,217,218,219,220,221,222,223,224,225,226,227,228,229,230,232,233,234,235,237,238,239,240,241);
UPDATE pipeline_items SET source_type = 'self_sourced', updated_at = updated_at WHERE source_type = '' AND id IN (242,243,244,245,247,248,249,250,251,252,253,254,257,258,259,262,263,264,265,266,267,268,269,270,271,272,273,274,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,298,299,300,301,302,303,304,305,306,307,308,309,310,311,312,313,314,315,316,317,318,319,320,321,322,323,324,325,326,327,328,329,331,332,333,334,335,336,337,338,339,340,341,342,343,344,345,346,347,348,349,350,351,352,353,354,355,356,357,358,359,360,361,362,363,364,365,366,367,368,369,370,371,372,373,374,375,376,377,378,379,380,381,382,383,385,386,387,388,389,390,391,392,393,394,395,396,397,398,400,402,403,404,405,406,407,408,409,410,414,415,416,417,418,422,423,425,426,427,428,429,430,431,432,433,434,435,436,437,438,441,444,445,447,449,451,452,453,454,455,456,457,458,459,460,461,462,463,464,465,466);
UPDATE pipeline_items SET source_type = 'self_sourced', updated_at = updated_at WHERE source_type = '' AND id IN (467,468,469,470,471,472,473,474,475,476,477,478,479,480,481,482,483,484,485,486,487,488,489,490,491,492,493,494,495,496,497,498,502,503,504,505,507,508,509,510,511,512,513,514,515,516,517,518,519,520,521,522,523,525,526,528,529,530,531,532,533,534,535,536,537,538,539,540,541,542,543,544,545,546,547,549,550,551,552,553,554,555,556,557,558,559,560,561,562,563,564,565,566,567,568,569,570,571,572,573,574,575,576,577,578,579,580,581,582,583,584,585,586,587,589,590,591,592,593,594,595,596,597,598,599,600,601,604,605,606,607,608,609,610,611,612,613,614,615,616,617,618,619,622,623,625,626,627,628,629,631,632,633,634,636,637,638,639,640,641,642,643,644,645,646,647,648,650,652,653,654,655,656,657,658,659,660,661,662,663,664,665,667,668,669,670,671,672,673,674,676,677,678,680,682,683,684,685,686,687);
UPDATE pipeline_items SET source_type = 'self_sourced', updated_at = updated_at WHERE source_type = '' AND id IN (688,689,690,691,692,693,694,696,697,698,699,700,702,704,705,706,707,708,709,711,713,714,715,716,717,718,719,720,721,722,723,724,725,726,727,728,729,730,731,732,733,734,735,736,737,738,739,740,742,744,745,746,747,748,749,750,751,752,753,754,755,756,757,758,759,760,761,762,764,765,766,767,768,769,770,771,772,773,774,775,776,777,778,779,780,781,782,783,784,785,786,787,788,789,790,791,792,793,794,795,796,797,798,799,800,801,802,803,804,805,806,807,809,810,811,812,813,814,815,816,819,820,823,824,827,828,829,830,831,832,833,834,835,836,837,838,839,840,841,842,843,844,846,847,848,849,851,852,853,854,855,856,857,858,859,860,861,862,863,864,865,866,867,868,869,870,871,872,873,874,875,876,878,879,880,881,882,883,884,885,886,887,888,896,905,909,910,913,914,917,918,922,926,933,938,939,940,942,946,947);
UPDATE pipeline_items SET source_type = 'self_sourced', updated_at = updated_at WHERE source_type = '' AND id IN (948,949,951,952,953,954,955,956,959,964,966,967,969,970,971,972,973,974,975,976,977);

-- C4) ลบประวัติดีลซ้ำที่สคริปต์เพิ่ม (886 แถว)
DELETE FROM pipeline_item_history WHERE id BETWEEN 1309 AND 2194 AND note = 'ข้อมูลเริ่มต้น (backfill ก่อนเริ่มเก็บ log)';

-- C5) ตัวนับรหัส PJ รายวัน = เลขสูงสุดที่เคยออกของแต่ละวัน
INSERT INTO project_code_counters (period, last_number) VALUES ('260105',2), ('260106',9), ('260107',2), ('260108',7), ('260109',3), ('260110',1), ('260112',4), ('260113',2), ('260114',1), ('260115',2), ('260116',2), ('260117',5), ('260119',3), ('260120',2), ('260121',3), ('260122',7), ('260126',2), ('260127',10), ('260128',1), ('260129',5), ('260131',1), ('260202',3), ('260203',7), ('260204',1), ('260205',8), ('260206',5), ('260207',2), ('260209',6), ('260210',4), ('260211',5), ('260212',3), ('260213',2), ('260214',1), ('260216',5), ('260217',1), ('260218',4), ('260219',4), ('260220',6), ('260221',2), ('260223',4), ('260224',4), ('260225',4), ('260226',5), ('260227',8), ('260228',6), ('260302',5), ('260304',1), ('260305',9), ('260306',3), ('260309',2);
INSERT INTO project_code_counters (period, last_number) VALUES ('260310',2), ('260311',7), ('260312',5), ('260313',1), ('260314',1), ('260316',5), ('260317',2), ('260319',5), ('260323',5), ('260324',2), ('260326',7), ('260327',5), ('260328',1), ('260330',3), ('260331',3), ('260401',1), ('260402',2), ('260403',12), ('260404',6), ('260406',4), ('260407',9), ('260408',2), ('260409',3), ('260410',9), ('260411',1), ('260416',2), ('260417',1), ('260420',7), ('260421',2), ('260422',2), ('260423',5), ('260424',1), ('260425',1), ('260427',1), ('260428',1), ('260429',7), ('260430',5), ('260505',2), ('260506',8), ('260508',8), ('260509',1), ('260511',1), ('260512',3), ('260513',5), ('260514',3), ('260515',3), ('260518',5), ('260519',2), ('260520',1), ('260521',4);
INSERT INTO project_code_counters (period, last_number) VALUES ('260522',2), ('260525',5), ('260526',1), ('260527',2), ('260528',7), ('260529',2), ('260601',2), ('260602',2), ('260604',4), ('260605',1), ('260608',6), ('260609',3), ('260610',1), ('260611',3), ('260612',7), ('260615',3), ('260616',2), ('260617',2), ('260618',4), ('260619',2), ('260622',3), ('260623',4), ('260624',2), ('260625',12), ('260626',3), ('260627',5), ('260629',3), ('260630',1), ('260701',4), ('260702',6), ('260703',5), ('260706',3), ('260707',2), ('260708',8), ('260709',2), ('260710',6), ('260711',4), ('260713',7), ('260714',1), ('260715',4), ('260716',3), ('260717',3), ('260720',5), ('260721',6), ('260722',4), ('260723',6), ('260724',3), ('260727',1), ('260729',1), ('260730',5);
INSERT INTO project_code_counters (period, last_number) VALUES ('260731',7), ('260803',5), ('260804',7), ('260805',4), ('260806',1), ('260807',9), ('260808',1), ('260810',6), ('260811',8), ('260813',3), ('260814',4), ('260817',4), ('260818',2), ('260819',10), ('260820',173), ('260821',3), ('260824',9), ('260825',4), ('260826',6), ('260827',3), ('260828',4), ('260829',1), ('260831',8), ('260903',1), ('260904',3), ('260907',4), ('260908',6), ('260909',16), ('260910',4), ('260911',3), ('260914',1), ('260915',4), ('260916',1), ('260917',1), ('260918',3), ('260921',2), ('260922',3), ('260923',2), ('260924',2), ('260928',7), ('260929',2), ('260930',1), ('261001',1), ('261002',1), ('261005',3), ('261006',7), ('261007',1), ('261008',1), ('261009',1), ('261012',1);
INSERT INTO project_code_counters (period, last_number) VALUES ('261014',2), ('261015',2), ('261016',4), ('261019',10), ('261020',1);
COMMIT;
