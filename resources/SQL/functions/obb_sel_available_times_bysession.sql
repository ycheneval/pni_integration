-- Function: salesforcetraining2.obb_sel_available_times_bysession(character, integer, character)

-- DROP FUNCTION salesforcetraining2.obb_sel_available_times_bysession(character, integer, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_available_times_bysession(IN session_id character, IN duration integer, IN room_type character)
  RETURNS TABLE(day character varying, start_time timestamp without time zone, end_time timestamp without time zone, matching integer) AS
$BODY$
DECLARE
	_start_date timestamp;
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	_avail_rooms integer;
	event_id character varying(18);
	_is_host_timeslot integer;
	
BEGIN
/*
    Author: YCH
    History:    V1.0  Jul 02, 2015: Creation
		V1.1	Jan 17, 2017: Replaced datediff with OVERLAPS
*/
	DROP table IF EXISTS _rooms;
	CREATE temporary TABLE _rooms (
		room_id character varying(18),
		room_name character varying(80),
		room_type character varying(255)
	);

	event_id := (SELECT event__c FROM Session__c se WHERE se.sfid = session_id);

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
	INSERT INTO _event_timeslots (cur_day, start_time, end_time, matching) SELECT *, 0 from obb_sel_timeslots(event_id, duration);

	DROP table IF EXISTS _host_timeslots;
	CREATE temporary TABLE  _host_timeslots (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp
	);
	INSERT INTO _host_timeslots (cur_day, start_time, end_time) SELECT * from obb_sel_timeslots_askedby_host(session_id, duration);

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

	-- Now go through all the timeslots and check if we have a timeslot available.
	-- To do that we count the remaining rooms that have the slot available. If 0, we
	-- remove the slot from the table
	FOR _time_slot IN
		SELECT * FROM _event_timeslots
	LOOP
		-- Is the current time slot free ?
		-- _time_slot.start_time;
		-- _avail_rooms := 1;
		_is_host_timeslot = LEAST(1, (SELECT COUNT(*) FROM _host_timeslots hts WHERE hts.start_time <= _time_slot.start_time AND  hts.end_time >= _time_slot.end_time));
		_avail_rooms = (SELECT COUNT(room_id) FROM _rooms WHERE room_id NOT IN (
			SELECT room_id FROM _room_timeslots roo
			WHERE (_time_slot.start_time, _time_slot.end_time) OVERLAPS (roo.start_time, roo.end_time)
			));

		IF _avail_rooms = 0 THEN
			DELETE FROM _event_timeslots ts WHERE ts.start_time = _time_slot.start_time;
		ELSE
			-- Check if we need to update the _temp_timeslots
			--RETURN QUERY SELECT '2015-01-22'::character varying(11), _time_slot.start_time, _time_slot.end_time, 10;
		        UPDATE _event_timeslots ets SET matching = _is_host_timeslot WHERE ets.start_time = _time_slot.start_time;
		END IF;

	END LOOP;
	
	--RETURN QUERY SELECT * from _rooms;
	RETURN QUERY SELECT * from _event_timeslots order by start_time;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_available_times_bysession(character, integer, character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_available_times_bysession(character, integer, character) IS 'TBB Timeslots for whole Event with matching timeslots';


-- SET search_path = salesforcetraining2, public;
-- SELECT * FROM obb_sel_available_times_bysession('a0Wb0000002lQimEAE',15,'Public Figures')