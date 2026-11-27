-- Mahavir Ice Cream – seed data
-- Menu prices from the shop's menu board dated 1 April 2026.
-- Kulfi section (Havmor packaged items) has no price on the board: inserted
-- INACTIVE with a 0.00 placeholder; the owner sets prices and activates them in admin.
-- Staff/owner accounts are NOT seeded: run database/create_owner.php.

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
('shop_name_mr','महावीर आईस्क्रीम'),
('shop_name_en','Mahaveer Ice Cream'),
('shop_address_mr','धुळे रोड, पाच पाऊली मंदिराजवळ, हनुमान नगर, अमळनेर 425401'),
('shop_address_en','Dhule Road, near Pach Pauli Mandir, Hanuman Nagar, Amalner 425401'),
('shop_phone','+91 99222 20000'),
('whatsapp_number',''),
('open_time','10:00'),
('close_time','22:30'),
('last_order_mins','30'),
('parcel_charge','5'),
('slot_mins','15'),
('slot_capacity','10'),
('min_lead_mins','15'),
('noshow_after_mins','30'),
('noshow_block_count','2'),
('bulk_notice_days','1'),
('table_unsettled_mins','120'),
('bill_footer_mr','धन्यवाद! पुन्हा भेट द्या'),
('bill_footer_en','Thank you, visit again'),
('google_maps_url','');

INSERT INTO sequences (name, last_value) VALUES ('bill_no', 0);

INSERT INTO categories (id, name_mr, name_en, sort_order) VALUES
(1,'आईस्क्रीम','Ice Cream',1),
(2,'लस्सी','Lassi',2),
(3,'शेक','Shakes',3),
(4,'कुल्फी','Kulfi',4),
(5,'श्रीखंड','Shrikhand',5);

INSERT INTO items (id, category_id, name_mr, name_en, sort_order, is_active) VALUES
(1,1,'वॅनिला','Vanilla',1,1),
(2,1,'काजु','Kaju',2,1),
(3,1,'मॅंगो','Mango',3,1),
(4,1,'अंजीर','Anjeer',4,1),
(5,1,'गुलकंद','Gulkand',5,1),
(6,1,'स्ट्रॉबेरी','Strawberry',6,1),
(7,1,'पिस्ता','Pista',7,1),
(8,1,'टूटी फ्रुटी','Tutti Frutti',8,1),
(9,1,'टेंडर कोकोनट','Tender Coconut',9,1),
(10,1,'मावा','Mawa',10,1),
(11,1,'बदाम','Badam',11,1),
(12,1,'बटर स्कॉच','Butterscotch',12,1),
(13,1,'चॉकलेट','Chocolate',13,1),
(14,1,'अमेरिकन ड्रायफ्रुट','American Dryfruit',14,1),
(15,1,'महावीर स्पेशल','Mahavir Special',15,1),
(16,1,'राजभोग','Rajbhog',16,1),
(17,2,'प्लेन लस्सी','Plain Lassi',1,1),
(18,2,'मॅंगो लस्सी','Mango Lassi',2,1),
(19,2,'लस्सी + आईस्क्रीम','Lassi + Ice Cream',3,1),
(20,2,'शेक + आईस्क्रीम','Shake + Ice Cream',4,1),
(21,3,'बदाम शेक','Badam Shake',1,1),
(22,3,'अंजीर शेक','Anjeer Shake',2,1),
(23,3,'कोल्ड कोको','Cold Coco',3,1),
(24,3,'दुध कोल्ड्रींक्स','Dudh Cold Drinks',4,1),
(25,3,'फालुदा','Falooda',5,1),
(26,4,'कुल्फी','Kulfi',1,0),
(27,4,'कोन','Cone',2,0),
(28,4,'चोकोबार','Chocobar',3,0),
(29,4,'मॅंगो डॉली','Mango Dolly',4,0),
(30,4,'राजबेरी डॉली','Rajberry Dolly',5,0),
(31,4,'फ्रॉस्टी','Frosty',6,0),
(32,5,'केशरी ड्रायफ्रुट श्रीखंड','Kesari Dryfruit Shrikhand',1,1);

INSERT INTO item_variants (item_id, label_mr, label_en, price, sort_order) VALUES
(1,'हाफ','Half',15,1),(1,'फुल','Full',30,2),
(2,'हाफ','Half',20,1),(2,'फुल','Full',40,2),
(3,'हाफ','Half',20,1),(3,'फुल','Full',40,2),
(4,'हाफ','Half',20,1),(4,'फुल','Full',40,2),
(5,'हाफ','Half',20,1),(5,'फुल','Full',40,2),
(6,'हाफ','Half',20,1),(6,'फुल','Full',40,2),
(7,'हाफ','Half',20,1),(7,'फुल','Full',40,2),
(8,'हाफ','Half',20,1),(8,'फुल','Full',40,2),
(9,'हाफ','Half',25,1),(9,'फुल','Full',50,2),
(10,'हाफ','Half',25,1),(10,'फुल','Full',50,2),
(11,'हाफ','Half',25,1),(11,'फुल','Full',50,2),
(12,'हाफ','Half',25,1),(12,'फुल','Full',50,2),
(13,'हाफ','Half',25,1),(13,'फुल','Full',50,2),
(14,'हाफ','Half',30,1),(14,'फुल','Full',60,2),
(15,'हाफ','Half',30,1),(15,'फुल','Full',60,2),
(16,'हाफ','Half',30,1),(16,'फुल','Full',60,2),
(17,'हाफ','Half',25,1),(17,'फुल','Full',50,2),
(18,'हाफ','Half',30,1),(18,'फुल','Full',60,2),
(19,'हाफ','Half',45,1),(19,'फुल','Full',70,2),
(20,'हाफ','Half',60,1),(20,'फुल','Full',99,2),
(21,'हाफ','Half',40,1),(21,'फुल','Full',80,2),
(22,'हाफ','Half',40,1),(22,'फुल','Full',80,2),
(23,'हाफ','Half',35,1),(23,'फुल','Full',70,2),
(24,'फुल','Full',70,1),
(25,'फुल','Full',90,1),
(26,'नग','Piece',0,1),
(27,'नग','Piece',0,1),
(28,'नग','Piece',0,1),
(29,'नग','Piece',0,1),
(30,'नग','Piece',0,1),
(31,'नग','Piece',0,1),
(32,'२५० ग्रॅम','250 g',100,1),(32,'५०० ग्रॅम','500 g',200,2);

-- 7 tables: T1–T6 inside, T7 outside. Random QR tokens; regenerate from admin any time.
INSERT INTO dining_tables (code, area, qr_token, sort_order) VALUES
('T1','inside', MD5(CONCAT(UUID(), RAND())), 1),
('T2','inside', MD5(CONCAT(UUID(), RAND())), 2),
('T3','inside', MD5(CONCAT(UUID(), RAND())), 3),
('T4','inside', MD5(CONCAT(UUID(), RAND())), 4),
('T5','inside', MD5(CONCAT(UUID(), RAND())), 5),
('T6','inside', MD5(CONCAT(UUID(), RAND())), 6),
('T7','outside',MD5(CONCAT(UUID(), RAND())), 7);

-- Website placeholders (owner replaces from admin)
INSERT INTO site_content (content_key, value_mr, value_en, is_active) VALUES
('about','TODO: मालकांकडून दुकानाचा इतिहास (स्थापना वर्ष, कुटुंबाची कथा)','TODO: shop history from the owner (founding year, family story)',1),
('banner','','',0);
