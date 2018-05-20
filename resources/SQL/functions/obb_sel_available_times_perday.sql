-- Function: salesforcetraining2.obb_sel_available_times(character, character, integer, character)

-- DROP FUNCTION salesforcetraining2.obb_sel_available_times(character, character, integer, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_available_times(IN event_id character, IN start_day character, IN duration integer, IN room_type character)
  RETURNS TABLE(start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: V1.0  Jul 02, 2015: Creation
		V1.1 Aug 25, Added _slot_increment
		V1.2 Sep 18, Set _slot_increment to 20 is duration = 20
		V1.3 Dec 16, Allowed to call with room_type empty -> get all room types
		V1.4 Jan 17, 2017: Replaced datediff with OVERLAPS
*/	
	_start_date timestamp;
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	_avail_rooms integer;
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
	
	IF 0 = char_length(room_type) THEN
		INSERT INTO _rooms (room_id, room_name, room_type) SELECT * from obb_sel_bilateral_rooms(event_id);
	ELSE
		INSERT INTO _rooms (room_id, room_name, room_type) SELECT * from obb_sel_bilateral_rooms(event_id, room_type);
	END IF;

	DROP table IF EXISTS _temp_timeslots;
	CREATE temporary TABLE  _temp_timeslots (
		start_time timestamp,
		end_time timestamp
	);
	/* Calculate all possible start time based on duration of each meetings */
	_start_date = start_day || ' 00:00:00';
	FOR _availability IN
		SELECT start_time__c, end_time__c FROM bilateral_availability__c ba
		INNER JOIN Programme__c p ON ba.programme_name__c = p.sfid
		WHERE ba.date__c = _start_date and p.event__c = event_id
			AND ba.isdeleted = FALSE
	LOOP
		_start_date = start_day || ' ' || _availability.start_time__c;
		_start_date_endslot = _start_date + duration * INTERVAL '1 minute';
		_end_date := start_day || ' ' || _availability.end_time__c;
		_end_date := _end_date - duration * INTERVAL '1 minute';
		INSERT INTO _temp_timeslots (start_time, end_time) SELECT _start_date, _start_date_endslot;
		/* All meeting can start every 'duration' minutes */
		WHILE _start_date < _end_date LOOP
			_start_date := _start_date + _slot_increment * INTERVAL '1 minute'; 
			_start_date_endslot = _start_date + duration * INTERVAL '1 minute';
			INSERT INTO _temp_timeslots (start_time, end_time) SELECT _start_date, _start_date_endslot;
		END LOOP;
	END LOOP;

	-- Now we have all possible time slots. 
	-- We need to iterate through all slots, and make sure there is at least 1 room
	-- available for the slot
	-- So create the _room_timeslots table
	DROP table IF EXISTS _room_timeslots;
	CREATE temporary TABLE  _room_timeslots (
		room_id character varying(18),
		start_time timestamp,
		end_time timestamp
	);

	IF 0 = char_length(room_type) THEN
		INSERT INTO _room_timeslots (room_id, start_time, end_time) SELECT et.room_id, et.start_time, et.end_time FROM obb_sel_room_existing_timeslots(event_id) et;
	ELSE
		INSERT INTO _room_timeslots (room_id, start_time, end_time) SELECT et.room_id, et.start_time, et.end_time FROM obb_sel_room_existing_timeslots(event_id, room_type) et;
	END IF;

	FOR _time_slot IN
		SELECT * FROM _temp_timeslots
	LOOP
		-- Is the current time slot free ?		
		_avail_rooms = (SELECT COUNT(room_id) FROM _rooms WHERE room_id NOT IN (
			SELECT room_id FROM _room_timeslots roo
			WHERE (_time_slot.start_time, _time_slot.end_time) OVERLAPS (roo.start_time, roo.end_time)
			));

		IF _avail_rooms = 0 THEN
			DELETE FROM _temp_timeslots ts WHERE ts.start_time = _time_slot.start_time;
		END IF;

	END LOOP;
		
	RETURN QUERY SELECT * from _temp_timeslots;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_available_times(character, character, integer, character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_available_times(character, character, integer, character) IS 'TBB Timeslots';

-- SET search_path = salesforcetraining2, public;
-- SELECT * from salesforcetraining2.obb_sel_available_times('a0Pb0000000GePmEAK', '2015-01-23', 20, 'Business');
