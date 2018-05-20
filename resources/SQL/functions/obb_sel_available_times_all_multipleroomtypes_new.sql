-- Function: salesforcetraining2.obb_sel_available_times_new(character, integer, character varying[])

-- DROP FUNCTION salesforcetraining2.obb_sel_available_times_new(character, integer, character varying[]);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_available_times_new(
    IN event_id character,
    IN duration integer,
    IN room_type character varying[])
  RETURNS TABLE(day character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: 	V1.0	Dec 16, 2015: Creation, allowed to have an array of strings as room types
		V1.1	Jan 20, 2016: Replaced datediff with OVERLAPS
*/	
	_start_date timestamp;
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	_avail_rooms integer;
	_status_draft varchar := 'Draft';
	_status_open varchar := 'Open';
	_slot_increment integer := 15;	
BEGIN
	IF duration = 20 THEN
		_slot_increment := 20;
	END IF;
	
	DROP table IF EXISTS _rooms;
	CREATE temporary TABLE _rooms (
		room_id character varying(18),
		room_name character varying(80),
		room_type character varying(255)
	);

	INSERT INTO _rooms (room_id, room_name, room_type) SELECT * from obb_sel_bilateral_rooms(event_id, room_type);

	DROP table IF EXISTS _temp_timeslots;
	CREATE temporary TABLE  _temp_timeslots (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp
	);
	
	
	FOR _availability IN
		SELECT date__c, start_time__c, end_time__c FROM bilateral_availability__c ba
		INNER JOIN Programme__c p ON ba.programme_name__c = p.sfid
		WHERE p.event__c = event_id
			AND ba.isdeleted = FALSE
		ORDER BY ba.start_time__c
	LOOP
		_start_date = _availability.date__c || ' ' || _availability.start_time__c;
		_start_date_endslot = _start_date + duration * INTERVAL '1 minute';
		_end_date := _availability.date__c || ' ' || _availability.end_time__c;
		_end_date := _end_date - duration * INTERVAL '1 minute';
		INSERT INTO _temp_timeslots (cur_day, start_time, end_time) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _start_date_endslot;
		/* All meeting can start every 'duration' minutes */
		WHILE _start_date < _end_date LOOP
			_start_date := _start_date + _slot_increment * INTERVAL '1 minute'; 
			_start_date_endslot = _start_date + duration * INTERVAL '1 minute';
			INSERT INTO _temp_timeslots (cur_day, start_time, end_time) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _start_date_endslot;
		END LOOP;
	END LOOP;	
	
	
	DROP table IF EXISTS _room_timeslots;
	CREATE temporary TABLE  _room_timeslots (
		room_id character varying(18),
		start_time timestamp,
		end_time timestamp
	);
	INSERT INTO _room_timeslots (room_id, start_time, end_time) SELECT et.room_id, et.start_time, et.end_time FROM obb_sel_room_existing_timeslots(event_id, room_type) et;

	FOR _time_slot IN
		SELECT * FROM _temp_timeslots
	LOOP
		_avail_rooms = (SELECT COUNT(room_id) FROM _rooms WHERE room_id NOT IN (
			SELECT room_id FROM _room_timeslots roo
			WHERE (_time_slot.start_time, _time_slot.end_time) OVERLAPS (roo.start_time, roo.end_time)
			));

		IF _avail_rooms = 0 THEN
			DELETE FROM _temp_timeslots ts WHERE ts.start_time = _time_slot.start_time;
		END IF;

	END LOOP;
		
	RETURN QUERY SELECT * from _temp_timeslots order by start_time;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_available_times_new(character, integer, character varying[])
  OWNER TO ue6lindbc6iam1;
