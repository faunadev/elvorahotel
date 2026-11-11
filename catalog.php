<?php
function addon_catalog(): array { return [
 'breakfast'=>['name'=>'Breakfast at the table','description'=>'A slow morning, fresh coffee and a seasonal breakfast.','price'=>650,'unit'=>'guest/night','icon'=>'☕'],
 'transfer'=>['name'=>'Airport arrival transfer','description'=>'One private vehicle for up to six guests; share your flight in requests.','price'=>1800,'unit'=>'stay','icon'=>'↗'],
 'romance'=>['name'=>'A little romance','description'=>'Flowers, a sweet welcome and a thoughtfully prepared room.','price'=>2200,'unit'=>'stay','icon'=>'♡'],
 'spa'=>['name'=>'Time to unwind','description'=>'One 60-minute massage for one adult; arrange your time at reception.','price'=>1500,'unit'=>'stay','icon'=>'✧'],
 'late'=>['name'=>'Stay a little longer','description'=>'Guaranteed 2 PM checkout instead of noon.','price'=>1000,'unit'=>'stay','icon'=>'◷'],
 'celebrate'=>['name'=>'Something to celebrate','description'=>'A small celebration cake and a handwritten card.','price'=>1200,'unit'=>'stay','icon'=>'✳'],
]; }
function room_photos(): array { return [
1=>'photo-1611892440504-42a792e24d32',2=>'photo-1590490360182-c33d57733427',3=>'photo-1566665797739-1674de7a421a',4=>'photo-1591088398332-8a7791972843',5=>'photo-1582719478250-c89cae4dc85b',6=>'photo-1560448204-e02f11c3d0e2',7=>'photo-1578683010236-d716f9a3f461',8=>'photo-1618773928121-c32242e63f39']; }
function photo_url(string $id, int $width=1000): string { foreach (room_photos() as $key => $photo) { if ($photo === $id && is_file(__DIR__.'/assets/room-'.$key.'.jpg')) return 'assets/room-'.$key.'.jpg'; }
 return 'https://images.unsplash.com/'.$id.'?auto=format&fit=crop&w='.$width.'&q=85'; }
