-- Function: salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character)

-- DROP FUNCTION salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_room_by_slot(IN event_id character, IN _start_date timestamp without time zone, IN duration integer, IN room_type character)
  RETURNS TABLE(room_id character varying, room_name character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: V1.0  Jul 02, 2015: Creation
             V1.1  Jul 13, 2015: Now ordering by room_name
             V1.2  Jul 20, 2015: Checking that _start_date is within the allowed time ranges
	     V1.3  Aug 27, 2015: Removed useless code for computing "matching" field
             V1.4  Jan 17, 2017: Optimized by removing the call to datediff
*/
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	_avail_rooms integer;
	_nb_valid_timeslot integer;
	
BEGIN
	DROP table IF EXISTS _rooms;
	CREATE temporary TABLE _rooms (
		room_id character varying(18),
		room_name character varying(80),
		room_type character varying(255)
	);

	INSERT INTO _rooms (room_id, room_name, room_type) SELECT * from obb_sel_bilateral_rooms(event_id, room_type);

	DROP table IF EXISTS _event_timeslots;
	CREATE temporary TABLE  _event_timeslots (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp,
		matching integer
	);
	-- Calculate all possible start time based on duration of each meeting
	-- Now we need to find the beginning of 
        _start_date_endslot = _start_date + duration * INTERVAL '1 minute';
        -- We need to check that this is a valid timeslot
	_nb_valid_timeslot := (SELECT count(*) from obb_sel_available_times(event_id, to_char(_start_date, 'YYYY-MM-DD'), duration, room_type) at where (at.start_time, at.end_time) OVERLAPS (_start_date, _start_date_endslot));
	IF (_nb_valid_timeslot > 0) THEN
		INSERT INTO _event_timeslots (cur_day, start_time, end_time, matching) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _start_date_endslot, 0; 
	END IF;

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
	INSERT INTO _room_timeslots (room_id, start_time, end_time) SELECT et.room_id, et.start_time, et.end_time FROM obb_sel_room_existing_timeslots(event_id, room_type) et;

        -- Creates the return table
	DROP table IF EXISTS _room_list;
	CREATE temporary TABLE  _room_list (
		room_id character varying(18),
		room_name character varying(80),
		start_time timestamp,
		end_time timestamp
	);
	

	-- Now go through all the timeslots and check if we have a timeslot available.
	-- To do that we count the remaining rooms that have the slot available. If 0, we
	-- remove the slot from the table
	FOR _time_slot IN
		SELECT * FROM _event_timeslots
	LOOP
		-- Is the current time slot free ?
		INSERT INTO _room_list (room_id, room_name, start_time, end_time) SELECT r.room_id, r.room_name, _time_slot.start_time, _time_slot.end_time FROM _rooms r WHERE r.room_id NOT IN (
			SELECT roo.room_id FROM _room_timeslots roo
			WHERE (_time_slot.start_time, _time_slot.end_time) OVERLAPS (roo.start_time, roo.end_time)
			);
                
                _avail_rooms = (SELECT count(*) FROM _room_list);

		IF _avail_rooms = 0 THEN
			DELETE FROM _event_timeslots ts WHERE ts.start_time = _time_slot.start_time;
			DELETE FROM _room_list rl WHERE rl.start_time = _time_slot.start_time;
		END IF;

	END LOOP;
	
	RETURN QUERY SELECT * from _room_list order by room_name;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character) IS 'TBB list of rooms available for a given timeslot';

-- SET search_path = salesforcetraining2, public;
-- SELECT * FROM obb_sel_room_by_slot('a0Pb0000000GePmEAK','2015-01-22 07:15:00',15,'Public Figures')